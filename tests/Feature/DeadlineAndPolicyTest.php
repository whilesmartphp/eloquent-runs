<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\AddNumbersType;
use Tests\TestCase;
use Whilesmart\Runs\Events\RunRetryScheduled;
use Whilesmart\Runs\Jobs\ExecuteRun;
use Whilesmart\Runs\Models\Run;
use Whilesmart\Runs\Models\RunArtifact;

class DeadlineAndPolicyTest extends TestCase
{
    #[Test]
    public function a_run_past_its_deadline_cannot_be_claimed_before_the_sweep(): void
    {
        $this->createRun(['deadline_at' => now()->addMinute()->toIso8601String()]);

        $this->travel(2)->minutes();

        $this->claim()->assertNoContent();
    }

    #[Test]
    public function a_lease_expiring_at_the_deadline_times_the_run_out_instead_of_retrying(): void
    {
        Event::fake([RunRetryScheduled::class]);
        $runId = $this->createRun([
            'max_attempts' => 3,
            'deadline_at' => now()->addSeconds(60)->toIso8601String(),
        ])->json('data.run.id');
        $this->claim();

        $this->travel(61)->seconds();
        $this->artisan('runs:sweep');

        $this->getJson("/api/runs/{$runId}")->assertJsonPath('data.status', 'timed_out')->assertJsonPath('data.error_code', 'lease_expired');
        Event::assertNotDispatched(RunRetryScheduled::class);
    }

    #[Test]
    public function the_queue_does_not_start_a_run_past_its_deadline(): void
    {
        Queue::fake();
        AddNumbersType::$calls = 0;
        $runId = $this->createRun([
            'type' => 'add',
            'execution_mode' => 'queue',
            'input' => ['numbers' => [1]],
            'deadline_at' => now()->addMinute()->toIso8601String(),
        ])->json('data.run.id');

        $this->travel(2)->minutes();
        $this->app->call([new ExecuteRun(Run::where('public_id', $runId)->value('id')), 'handle']);

        $this->assertSame(0, AddNumbersType::$calls);
        $this->getJson("/api/runs/{$runId}")->assertJsonPath('data.status', 'queued')->assertJsonPath('data.attempt_count', 0);
    }

    #[Test]
    public function a_worker_cannot_force_a_retry_the_type_refuses(): void
    {
        $this->createRun(['max_attempts' => 3]);
        $claim = $this->claim();

        $this->asAttempt($claim->json('data.lease_token'))
            ->postJson('/api/runner/attempts/'.$claim->json('data.attempt.id').'/fail', ['error_code' => 'blocked_url', 'retryable' => true])
            ->assertJsonPath('data.status', 'failed');
    }

    #[Test]
    public function only_the_accepted_attempts_files_are_listed_by_default(): void
    {
        Storage::fake('local');
        $runId = $this->createRun()->json('data.run.id');
        $claim = $this->claim();

        $this->asAttempt($claim->json('data.lease_token'))->post('/api/runner/attempts/'.$claim->json('data.attempt.id').'/artifacts', [
            'file' => UploadedFile::fake()->createWithContent('a.png', 'partial'),
            'path' => 'a.png',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->getJson("/api/runs/{$runId}/artifacts")->assertJsonCount(0, 'data');
        $this->getJson("/api/runs/{$runId}/artifacts?all=1")->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_second_file_at_the_same_path_never_replaces_the_first(): void
    {
        Storage::fake('local');
        $runId = $this->createRun()->json('data.run.id');
        $claim = $this->claim();
        $upload = fn (string $bytes) => $this->asAttempt($claim->json('data.lease_token'))->post(
            '/api/runner/attempts/'.$claim->json('data.attempt.id').'/artifacts',
            ['file' => UploadedFile::fake()->createWithContent('a.png', $bytes), 'path' => 'a.png'],
            ['Accept' => 'application/json'],
        );

        $upload('first')->assertCreated();
        $upload('second')->assertStatus(409)->assertJsonPath('code', 'artifact_exists');

        $id = $this->getJson("/api/runs/{$runId}/artifacts?all=1")->json('data.0.id');
        $this->assertSame('first', $this->get("/api/runs/{$runId}/artifacts/{$id}")->streamedContent());
    }

    #[Test]
    public function an_upload_that_loses_a_race_for_its_path_leaves_the_winners_file(): void
    {
        Storage::fake('local');
        $runId = $this->createRun()->json('data.run.id');
        $claim = $this->claim();

        RunArtifact::creating(function (RunArtifact $artifact) {
            static $raced = false;
            if ($raced) {
                return;
            }
            $raced = true;
            Storage::disk('local')->put('winner', 'winner-bytes');
            DB::table('work_run_artifacts')->insert([
                ...$artifact->getAttributes(),
                'public_id' => strtolower((string) str()->ulid()),
                'sha256' => hash('sha256', 'winner-bytes'),
                'storage_key' => 'winner',
                'metadata' => null,
            ]);
        });

        $this->asAttempt($claim->json('data.lease_token'))->post(
            '/api/runner/attempts/'.$claim->json('data.attempt.id').'/artifacts',
            ['file' => UploadedFile::fake()->createWithContent('a.png', 'loser-bytes'), 'path' => 'a.png'],
            ['Accept' => 'application/json'],
        )->assertStatus(409)->assertJsonPath('code', 'artifact_exists');

        $id = $this->getJson("/api/runs/{$runId}/artifacts?all=1")->assertJsonCount(1, 'data')->json('data.0.id');
        $this->assertSame('winner-bytes', $this->get("/api/runs/{$runId}/artifacts/{$id}")->streamedContent());
        $this->assertSame(['winner'], Storage::disk('local')->allFiles());
    }
}
