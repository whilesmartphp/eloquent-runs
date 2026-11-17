<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\AddNumbersType;
use Tests\Fixtures\FingerprintedStoreRequest;
use Tests\Fixtures\InvalidResultType;
use Tests\Fixtures\RetryInvalidResultType;
use Tests\TestCase;
use Whilesmart\Runs\Events\RunCreated;
use Whilesmart\Runs\Http\Requests\StoreRunRequest;
use Whilesmart\Runs\Jobs\ExecuteRun;
use Whilesmart\Runs\Models\Run;

class QueueDeliveryTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $connection = ['driver' => 'database', 'connection' => $app['config']->get('database.default'), 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 420];
        $app['config']->set('queue.connections.delivery', $connection);
        $app['config']->set('queue.connections.unavailable', [...$connection, 'table' => 'missing_queue_table']);
        $app['config']->set('runs.queue.connection', 'delivery');
        $app['config']->set('runs.types.invalid', InvalidResultType::class);
        $app['config']->set('runs.types.invalid_retry', RetryInvalidResultType::class);
    }

    private function work(): void
    {
        $this->artisan('queue:work', ['connection' => 'delivery', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertSuccessful();
    }

    private function queued(array $overrides = []): string
    {
        return $this->createRun(['type' => 'add', 'execution_mode' => 'queue', 'input' => ['numbers' => [2, 3]], ...$overrides])
            ->assertCreated()->json('data.run.id');
    }

    #[Test]
    public function a_failed_queue_push_survives_commit_and_recovers(): void
    {
        config(['runs.queue.connection' => 'unavailable']);
        $id = $this->queued(['idempotency_key' => 'delivery-check']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('work_run_dispatches', 1);
        $this->createRun(['type' => 'add', 'execution_mode' => 'queue', 'input' => ['numbers' => [2, 3]], 'idempotency_key' => 'delivery-check'])
            ->assertOk()->assertJsonPath('data.run.id', $id);
        $this->assertDatabaseHas('work_run_dispatches', ['dispatch_attempts' => 1]);
        config(['runs.queue.connection' => 'delivery']);
        $this->travel(31)->seconds();
        $this->artisan('runs:sweep')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        $this->getJson('/api/runs/'.$id)->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.sum', 5);
        $this->assertDatabaseCount('work_run_attempts', 1);
        $this->assertDatabaseCount('work_run_dispatches', 0);
    }

    #[Test]
    public function lost_messages_are_replaced_without_flooding_the_queue(): void
    {
        $id = $this->queued();
        $this->artisan('queue:clear', ['connection' => 'delivery', '--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->artisan('runs:sweep')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->travel(301)->seconds();
        $this->artisan('runs:sweep')->assertSuccessful();
        $this->artisan('runs:sweep')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $this->work();
        $this->getJson('/api/runs/'.$id)->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.attempt_count', 1);
    }

    #[Test]
    public function duplicate_messages_cannot_skip_retry_backoff(): void
    {
        AddNumbersType::$calls = 0;
        config(['runs.defaults.retry_backoff_seconds' => [60]]);
        $id = $this->queued(['max_attempts' => 3, 'input' => ['numbers' => [2, 3], 'fail_until' => 1]]);
        $this->work();
        $this->app['queue']->connection('delivery')->push(new ExecuteRun(Run::where('public_id', $id)->value('id')));
        $this->work();
        $this->assertSame(1, AddNumbersType::$calls);
        $this->getJson('/api/runs/'.$id)->assertJsonPath('data.status', 'queued')->assertJsonPath('data.attempt_count', 1);
        $this->travel(61)->seconds();
        $this->work();
        $this->getJson('/api/runs/'.$id)->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.attempt_count', 2);
        $this->assertDatabaseCount('work_run_dispatches', 0);
    }

    #[Test]
    public function invalid_results_settle_with_the_types_retry_policy(): void
    {
        $id = $this->queued(['type' => 'invalid', 'max_attempts' => 3]);
        $this->work();
        $this->getJson('/api/runs/'.$id)->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'invalid_result')->assertJsonPath('data.attempt_count', 1);
        $this->assertDatabaseHas('work_run_attempts', ['error_code' => 'invalid_result', 'status' => 'failed']);
        $this->assertDatabaseCount('work_run_dispatches', 0);
        $id = $this->queued(['type' => 'invalid_retry', 'max_attempts' => 3]);
        $this->work();
        $this->getJson('/api/runs/'.$id)->assertJsonPath('data.status', 'queued');
        $this->work();
        $this->getJson('/api/runs/'.$id)->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.attempt_count', 2);
    }

    #[Test]
    public function a_host_can_require_server_generated_fingerprints(): void
    {
        config(['runs.requests.store_run' => FingerprintedStoreRequest::class]);
        $request = ['type' => 'add', 'input' => ['numbers' => [1]], 'idempotency_key' => 'fingerprint-check'];
        $id = $this->createRun($request)->assertCreated()->json('data.run.id');
        $this->createRun($request)->assertOk()->assertJsonPath('data.run.id', $id);
        $this->createRun([...$request, 'input' => ['numbers' => [2]], 'request_fingerprint' => 'client-forgery'])
            ->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        config(['runs.requests.store_run' => StoreRunRequest::class]);
        $this->createRun($request)->assertConflict();
        $this->assertDatabaseCount('work_runs', 1);
    }

    #[Test]
    public function rolled_back_creations_do_not_dispatch_or_emit_events(): void
    {
        Event::fake([RunCreated::class]);
        DB::beginTransaction();
        $this->queued();
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('work_runs', 0);
        $this->assertDatabaseCount('work_run_dispatches', 0);
        $this->assertDatabaseCount('jobs', 0);
        Event::assertNotDispatched(RunCreated::class);
    }

    #[Test]
    public function cancelled_and_overdue_runs_are_not_redelivered(): void
    {
        $cancelled = $this->queued();
        $overdue = $this->queued(['deadline_at' => now()->addSeconds(10)->toIso8601String()]);
        $this->postJson('/api/runs/'.$cancelled.'/cancel')->assertOk();
        $this->artisan('queue:clear', ['connection' => 'delivery', '--force' => true])->assertSuccessful();
        $this->travel(301)->seconds();
        $this->artisan('runs:sweep')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('work_run_dispatches', 0);
        $this->assertDatabaseCount('work_run_attempts', 0);
        $this->getJson('/api/runs/'.$cancelled)->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/runs/'.$overdue)->assertJsonPath('data.status', 'timed_out');
    }
}
