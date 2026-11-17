<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunnerFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    #[Test]
    public function a_runner_claims_reports_progress_uploads_and_completes(): void
    {
        $runId = $this->createRun()->json('data.run.id');

        $claim = $this->claim()->assertOk()
            ->assertJsonPath('data.run.id', $runId)
            ->assertJsonPath('data.run.status', 'running')
            ->assertJsonPath('data.attempt.number', 1)
            ->assertJsonPath('data.attempt.executor_name', 'runner-1');
        $attempt = $claim->json('data.attempt.id');
        $token = $claim->json('data.lease_token');

        $this->claim()->assertNoContent();

        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/heartbeat", ['progress' => ['done' => 1, 'total' => 2]])
            ->assertOk()
            ->assertJsonPath('data.cancel_requested', false);

        $chunks = ['chunks' => [
            ['sequence' => 0, 'content' => 'opening page'],
            ['sequence' => 1, 'content' => 'captured desktop'],
        ]];
        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/logs", $chunks)->assertJsonPath('data.stored', 2);
        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/logs", $chunks)->assertJsonPath('data.stored', 0);

        $file = UploadedFile::fake()->createWithContent('desktop.png', 'png-bytes');
        $this->asAttempt($token)->post("/api/runner/attempts/{$attempt}/artifacts", [
            'file' => $file,
            'path' => 'shots/desktop.png',
            'type' => 'screenshot',
            'sha256' => hash('sha256', 'png-bytes'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.path', 'shots/desktop.png');

        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 1]])
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.result.images', 1);

        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 1]])->assertOk();
        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 2]])
            ->assertStatus(409)->assertJsonPath('code', 'already_completed');

        $this->getJson("/api/runs/{$runId}")->assertJsonPath('data.progress.done', 1);
        $this->getJson("/api/runs/{$runId}/logs")->assertJsonCount(2, 'data.chunks')->assertJsonPath('data.chunks.1.content', 'captured desktop');
        $artifacts = $this->getJson("/api/runs/{$runId}/artifacts")->assertJsonCount(1, 'data');
        $download = $this->get("/api/runs/{$runId}/artifacts/".$artifacts->json('data.0.id'))->assertOk();
        $this->assertSame('png-bytes', $download->streamedContent());
    }

    #[Test]
    public function a_runner_only_sees_runs_its_authorizer_allows(): void
    {
        $this->createRun(['owner_id' => 2]);

        $this->claim('runner-1')->assertNoContent();
        $this->withoutToken()->postJson('/api/runner/claim')->assertUnauthorized()->assertJsonPath('code', 'runner_unknown');
        $this->claim('runner-2')->assertOk();
    }

    #[Test]
    public function a_runner_can_ask_for_specific_types(): void
    {
        $this->createRun();

        $this->claim('runner-1', ['types' => ['add']])->assertNoContent();
        $this->claim('runner-1', ['types' => ['screenshot']])->assertOk();
    }

    #[Test]
    public function a_wrong_or_missing_token_is_refused(): void
    {
        $this->createRun();
        $attempt = $this->claim()->json('data.attempt.id');

        $this->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 1]])
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_token');
        $this->asAttempt('guess')->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 1]])
            ->assertUnauthorized();
    }

    #[Test]
    public function the_result_is_validated_by_the_run_type(): void
    {
        $this->createRun();
        $claim = $this->claim();

        $this->asAttempt($claim->json('data.lease_token'))
            ->postJson('/api/runner/attempts/'.$claim->json('data.attempt.id').'/complete', ['result' => ['images' => 0]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('result.images');
    }

    #[Test]
    public function a_checksum_mismatch_rejects_the_upload(): void
    {
        $this->createRun();
        $claim = $this->claim();

        $this->asAttempt($claim->json('data.lease_token'))->post('/api/runner/attempts/'.$claim->json('data.attempt.id').'/artifacts', [
            'file' => UploadedFile::fake()->createWithContent('a.png', 'bytes'),
            'path' => 'a.png',
            'sha256' => str_repeat('0', 64),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('sha256');

        $this->assertDatabaseCount('work_run_artifacts', 0);
    }

    #[Test]
    public function an_expired_lease_is_swept_and_the_run_retried_by_a_new_attempt(): void
    {
        $runId = $this->createRun(['max_attempts' => 2])->json('data.run.id');
        $first = $this->claim();

        $this->travel(91)->seconds();
        $this->artisan('runs:sweep')->assertSuccessful();

        $this->getJson("/api/runs/{$runId}")->assertJsonPath('data.status', 'queued')->assertJsonPath('data.error_code', 'lease_expired');

        $second = $this->claim()->assertOk()->assertJsonPath('data.attempt.number', 2);

        $this->asAttempt($first->json('data.lease_token'))
            ->postJson('/api/runner/attempts/'.$first->json('data.attempt.id').'/complete', ['result' => ['images' => 1]])
            ->assertStatus(409)->assertJsonPath('code', 'lease_lost');

        $this->asAttempt($second->json('data.lease_token'))
            ->postJson('/api/runner/attempts/'.$second->json('data.attempt.id').'/complete', ['result' => ['images' => 1]])
            ->assertOk();

        $this->getJson("/api/runs/{$runId}/attempts")
            ->assertJsonPath('data.0.status', 'expired')
            ->assertJsonPath('data.1.status', 'succeeded');
    }

    #[Test]
    public function heartbeats_keep_the_lease_alive(): void
    {
        $this->createRun();
        $claim = $this->claim();
        $attempt = $claim->json('data.attempt.id');
        $token = $claim->json('data.lease_token');

        $this->travel(60)->seconds();
        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/heartbeat")->assertOk();
        $this->travel(60)->seconds();
        $this->artisan('runs:sweep');

        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 1]])->assertOk();
    }

    #[Test]
    public function the_last_failure_fails_the_run(): void
    {
        $runId = $this->createRun(['max_attempts' => 2])->json('data.run.id');

        foreach ([['queued', 1], ['failed', 2]] as [$status, $number]) {
            $claim = $this->claim()->assertJsonPath('data.attempt.number', $number);
            $this->asAttempt($claim->json('data.lease_token'))
                ->postJson('/api/runner/attempts/'.$claim->json('data.attempt.id').'/fail', ['error_code' => 'browser_crashed'])
                ->assertOk()
                ->assertJsonPath('data.status', $status);
        }

        $this->getJson("/api/runs/{$runId}")->assertJsonPath('data.error_code', 'browser_crashed');
    }

    #[Test]
    public function an_error_the_type_does_not_retry_fails_at_once(): void
    {
        $this->createRun(['max_attempts' => 3]);
        $claim = $this->claim();

        $this->asAttempt($claim->json('data.lease_token'))
            ->postJson('/api/runner/attempts/'.$claim->json('data.attempt.id').'/fail', ['error_code' => 'blocked_url'])
            ->assertJsonPath('data.status', 'failed');
    }

    #[Test]
    public function cancelling_a_running_run_asks_the_runner_to_stop(): void
    {
        $runId = $this->createRun()->json('data.run.id');
        $claim = $this->claim();
        $attempt = $claim->json('data.attempt.id');
        $token = $claim->json('data.lease_token');

        $this->postJson("/api/runs/{$runId}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelling');
        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/heartbeat")->assertJsonPath('data.cancel_requested', true);
        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/fail", ['error_code' => 'cancelled', 'retryable' => true])
            ->assertJsonPath('data.status', 'cancelled');
    }

    #[Test]
    public function a_run_cancelled_while_its_runner_is_gone_settles_when_the_lease_expires(): void
    {
        $runId = $this->createRun(['max_attempts' => 3])->json('data.run.id');
        $this->claim();
        $this->postJson("/api/runs/{$runId}/cancel");

        $this->travel(91)->seconds();
        $this->artisan('runs:sweep');

        $this->getJson("/api/runs/{$runId}")->assertJsonPath('data.status', 'cancelled');
        $this->claim()->assertNoContent();
    }

    #[Test]
    public function a_queued_run_past_its_deadline_times_out(): void
    {
        $runId = $this->createRun(['deadline_at' => now()->addMinutes(5)->toIso8601String()])->json('data.run.id');

        $this->travel(6)->minutes();
        $this->artisan('runs:sweep');

        $this->getJson("/api/runs/{$runId}")->assertJsonPath('data.status', 'timed_out')->assertJsonPath('data.error_code', 'deadline_passed');
    }
}
