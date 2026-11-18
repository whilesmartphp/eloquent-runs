<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\Runs\Events\RunResumed;
use Whilesmart\Runs\Events\RunWaiting;

class WaitingTest extends TestCase
{
    #[Test]
    public function a_runner_parks_a_run_and_a_person_resumes_it(): void
    {
        Event::fake([RunWaiting::class, RunResumed::class]);
        $runId = $this->createRun()->json('data.run.id');
        $claim = $this->claim();
        $attempt = $claim->json('data.claims.0.attempt.id');

        $this->asAttempt($claim->json('data.claims.0.lease_token'))
            ->postJson("/api/runner/attempts/{$attempt}/wait", ['reason' => 'needs_login', 'state' => ['page' => 2]])
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.waiting_on.reason', 'needs_login');

        $this->claim()->assertNoContent();
        $this->getJson("/api/runs/{$runId}/attempts")->assertJsonPath('data.0.status', 'suspended');

        $this->postJson("/api/runs/{$runId}/resume", ['data' => ['password' => 'given']])
            ->assertOk()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.state.page', 2)
            ->assertJsonPath('data.state.password', 'given');

        $second = $this->claim()->assertOk()->assertJsonPath('data.claims.0.attempt.number', 2);
        $this->asAttempt($second->json('data.claims.0.lease_token'))
            ->postJson('/api/runner/attempts/'.$second->json('data.claims.0.attempt.id').'/complete', ['result' => ['images' => 1]])
            ->assertJsonPath('data.status', 'succeeded');

        Event::assertDispatched(RunWaiting::class);
        Event::assertDispatched(RunResumed::class);
    }

    #[Test]
    public function a_queue_handler_waits_for_an_answer_and_finishes_with_it(): void
    {
        $runId = $this->createRun(['type' => 'ask', 'execution_mode' => 'queue', 'input' => ['question' => 'Which plan?']])
            ->json('data.run.id');

        $this->getJson("/api/runs/{$runId}")
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.state.asked', 'Which plan?');

        $this->postJson("/api/runs/{$runId}/resume", ['data' => ['answer' => 'Pro']]);

        $this->getJson("/api/runs/{$runId}")
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.result.answer', 'Pro')
            ->assertJsonPath('data.attempt_count', 2);
    }

    #[Test]
    public function waiting_does_not_use_up_retries(): void
    {
        $runId = $this->createRun(['max_attempts' => 2])->json('data.run.id');
        $first = $this->claim();
        $this->asAttempt($first->json('data.claims.0.lease_token'))
            ->postJson('/api/runner/attempts/'.$first->json('data.claims.0.attempt.id').'/wait', ['reason' => 'paused']);
        $this->postJson("/api/runs/{$runId}/resume");

        $second = $this->claim();
        $this->asAttempt($second->json('data.claims.0.lease_token'))
            ->postJson('/api/runner/attempts/'.$second->json('data.claims.0.attempt.id').'/fail', ['error_code' => 'browser_crashed'])
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.failure_count', 1);
    }

    #[Test]
    public function the_sweep_resumes_a_run_when_its_timer_is_due(): void
    {
        $runId = $this->createRun()->json('data.run.id');
        $claim = $this->claim();
        $this->asAttempt($claim->json('data.claims.0.lease_token'))
            ->postJson('/api/runner/attempts/'.$claim->json('data.claims.0.attempt.id').'/wait', [
                'reason' => 'rate_limited',
                'resume_at' => now()->addMinutes(5)->toIso8601String(),
            ]);

        $this->artisan('runs:sweep');
        $this->getJson("/api/runs/{$runId}")->assertJsonPath('data.status', 'waiting');

        $this->travel(6)->minutes();
        $this->artisan('runs:sweep');
        $this->getJson("/api/runs/{$runId}")->assertJsonPath('data.status', 'queued');
    }

    #[Test]
    public function a_waiting_run_is_cancelled_at_once_and_times_out_at_its_deadline(): void
    {
        $cancelled = $this->createRun()->json('data.run.id');
        $claim = $this->claim();
        $this->asAttempt($claim->json('data.claims.0.lease_token'))
            ->postJson('/api/runner/attempts/'.$claim->json('data.claims.0.attempt.id').'/wait', ['reason' => 'paused']);
        $this->postJson("/api/runs/{$cancelled}/cancel")->assertJsonPath('data.status', 'cancelled');

        $overdue = $this->createRun(['deadline_at' => now()->addMinutes(5)->toIso8601String()])->json('data.run.id');
        $claim = $this->claim();
        $this->asAttempt($claim->json('data.claims.0.lease_token'))
            ->postJson('/api/runner/attempts/'.$claim->json('data.claims.0.attempt.id').'/wait', ['reason' => 'paused']);
        $this->travel(6)->minutes();
        $this->artisan('runs:sweep');
        $this->getJson("/api/runs/{$overdue}")->assertJsonPath('data.status', 'timed_out');
    }

    #[Test]
    public function only_a_waiting_run_can_be_resumed_and_reported_runs_cannot_wait(): void
    {
        $queued = $this->createRun()->json('data.run.id');
        $this->postJson("/api/runs/{$queued}/resume")->assertStatus(409)->assertJsonPath('code', 'not_waiting');

        $reported = $this->createRun(['execution_mode' => 'reported']);
        $this->asAttempt($reported->json('data.upload.lease_token'))
            ->postJson('/api/runner/attempts/'.$reported->json('data.upload.attempt.id').'/wait', ['reason' => 'paused'])
            ->assertStatus(409)->assertJsonPath('code', 'cannot_wait');
    }
}
