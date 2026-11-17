<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\Runs\Events\RunCreated;

class RunApiTest extends TestCase
{
    #[Test]
    public function a_run_is_queued_for_runners_by_default(): void
    {
        Event::fake([RunCreated::class]);

        $response = $this->createRun()
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.run.type', 'screenshot')
            ->assertJsonPath('data.run.execution_mode', 'pull')
            ->assertJsonPath('data.run.status', 'queued')
            ->assertJsonMissingPath('data.upload');

        $id = $response->json('data.run.id');
        $this->assertSame(26, strlen($id));
        $this->getJson("/api/runs/{$id}")->assertOk()->assertJsonPath('data.input.url', 'https://example.com');
        Event::assertDispatched(RunCreated::class);
    }

    #[Test]
    public function input_is_validated_by_the_run_type(): void
    {
        $this->createRun(['input' => ['url' => 'not a url']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('input.url');

        $this->createRun(['type' => 'unknown'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->assertDatabaseCount('work_runs', 0);
    }

    #[Test]
    public function the_same_idempotency_key_returns_the_first_run(): void
    {
        $first = $this->createRun(['idempotency_key' => 'pr-12-abc'])->assertCreated();
        $again = $this->createRun(['idempotency_key' => 'pr-12-abc'])->assertOk();

        $this->assertSame($first->json('data.run.id'), $again->json('data.run.id'));
        $this->createRun(['idempotency_key' => 'pr-12-abc', 'owner_id' => 2])->assertCreated();
        $this->assertDatabaseCount('work_runs', 2);
    }

    #[Test]
    public function runs_are_listed_newest_first_with_filters(): void
    {
        $this->createRun(['subject_type' => 'App\\Models\\PullRequest', 'subject_id' => 7]);
        $this->createRun(['type' => 'add', 'execution_mode' => 'pull', 'input' => ['numbers' => [1]]]);

        $this->getJson('/api/runs')->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.data.0.type', 'add');

        $this->getJson('/api/runs?subject_type=App%5CModels%5CPullRequest&subject_id=7')
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.data.0.subject_id', '7');
    }

    #[Test]
    public function cancelling_a_queued_run_settles_it_at_once(): void
    {
        $id = $this->createRun()->json('data.run.id');

        $this->postJson("/api/runs/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/runs/{$id}/cancel")->assertStatus(409)->assertJsonPath('code', 'run_finished');
        $this->claim()->assertNoContent();
    }
}
