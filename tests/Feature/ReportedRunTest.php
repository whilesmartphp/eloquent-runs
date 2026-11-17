<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportedRunTest extends TestCase
{
    #[Test]
    public function ci_uploads_files_and_settles_a_run_it_already_did(): void
    {
        Storage::fake('local');

        $created = $this->createRun(['execution_mode' => 'reported'])
            ->assertCreated()
            ->assertJsonPath('data.run.status', 'running')
            ->assertJsonPath('data.upload.attempt.number', 1);

        $runId = $created->json('data.run.id');
        $attempt = $created->json('data.upload.attempt.id');
        $token = $created->json('data.upload.lease_token');

        $this->claim()->assertNoContent();

        $this->asAttempt($token)->post("/api/runner/attempts/{$attempt}/artifacts", [
            'file' => UploadedFile::fake()->createWithContent('report.json', '{"ok":true}'),
            'path' => 'report.json',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 3]])
            ->assertJsonPath('data.status', 'succeeded');

        $this->getJson("/api/runs/{$runId}/artifacts")->assertJsonPath('data.0.path', 'report.json');
    }

    #[Test]
    public function a_failed_upload_is_never_retried(): void
    {
        $created = $this->createRun(['execution_mode' => 'reported', 'max_attempts' => 5]);

        $this->asAttempt($created->json('data.upload.lease_token'))
            ->postJson('/api/runner/attempts/'.$created->json('data.upload.attempt.id').'/fail', ['error_code' => 'browser_crashed'])
            ->assertJsonPath('data.status', 'failed');
    }

    #[Test]
    public function cancelling_a_reported_run_closes_its_upload(): void
    {
        $created = $this->createRun(['execution_mode' => 'reported']);

        $this->postJson('/api/runs/'.$created->json('data.run.id').'/cancel')->assertJsonPath('data.status', 'cancelled');

        $this->asAttempt($created->json('data.upload.lease_token'))
            ->postJson('/api/runner/attempts/'.$created->json('data.upload.attempt.id').'/complete', ['result' => ['images' => 1]])
            ->assertStatus(409);
    }
}
