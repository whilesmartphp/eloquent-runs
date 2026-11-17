<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\Runs\Models\RunArtifact;
use Whilesmart\Runs\Services\RunService;

class ManifestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function file(string $path, string $bytes, string $type = 'image/png'): array
    {
        return ['path' => $path, 'media_type' => $type, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    #[Test]
    public function completion_waits_for_every_declared_file(): void
    {
        $created = $this->createRun(['execution_mode' => 'reported']);
        $runId = $created->json('data.run.id');
        $attempt = $created->json('data.upload.attempt.id');
        $as = fn () => $this->asAttempt($created->json('data.upload.lease_token'));

        $as()->postJson("/api/runner/attempts/{$attempt}/manifest", ['files' => [
            $this->file('head/home.png', 'head-bytes'),
            $this->file('base/home.png', 'base-bytes'),
        ]])->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.received', false);

        $as()->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 2]])
            ->assertStatus(409)
            ->assertJsonPath('code', 'artifacts_missing')
            ->assertJsonPath('details.paths', ['base/home.png', 'head/home.png']);

        $as()->post("/api/runner/attempts/{$attempt}/artifacts", [
            'file' => UploadedFile::fake()->createWithContent('home.png', 'other-bytes'),
            'path' => 'head/home.png',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

        foreach (['head' => 'head-bytes', 'base' => 'base-bytes'] as $side => $bytes) {
            $as()->post("/api/runner/attempts/{$attempt}/artifacts", [
                'file' => UploadedFile::fake()->createWithContent('home.png', $bytes),
                'path' => "{$side}/home.png",
            ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.received', true);
        }

        $as()->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 2]])->assertJsonPath('data.status', 'succeeded');
        $this->getJson("/api/runs/{$runId}/artifacts")->assertJsonCount(2, 'data');
    }

    #[Test]
    public function a_declaration_cannot_change_once_made(): void
    {
        $created = $this->createRun(['execution_mode' => 'reported']);
        $url = '/api/runner/attempts/'.$created->json('data.upload.attempt.id').'/manifest';
        $as = fn () => $this->asAttempt($created->json('data.upload.lease_token'));

        $as()->postJson($url, ['files' => [$this->file('a.png', 'one')]])->assertOk();
        $as()->postJson($url, ['files' => [$this->file('a.png', 'one')]])->assertOk();
        $as()->postJson($url, ['files' => [$this->file('a.png', 'two')]])->assertStatus(409)->assertJsonPath('code', 'artifact_exists');
    }

    #[Test]
    public function files_not_yet_received_are_hidden_from_readers(): void
    {
        $created = $this->createRun(['execution_mode' => 'reported']);
        $this->asAttempt($created->json('data.upload.lease_token'))
            ->postJson('/api/runner/attempts/'.$created->json('data.upload.attempt.id').'/manifest', ['files' => [$this->file('a.png', 'one')]]);

        $this->getJson('/api/runs/'.$created->json('data.run.id').'/artifacts?all=1')->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_host_can_store_declared_bytes_itself(): void
    {
        $created = $this->createRun(['execution_mode' => 'reported']);
        $attempt = $created->json('data.upload.attempt.id');
        $token = $created->json('data.upload.lease_token');
        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/manifest", ['files' => [
            $this->file('a.png', 'aaa'),
            $this->file('b.png', 'bbb'),
        ]]);
        $runs = app(RunService::class);
        $a = RunArtifact::where('path', 'a.png')->first();
        $b = RunArtifact::where('path', 'b.png')->first();

        $this->expectsMismatch(fn () => $runs->receiveArtifact($a, 'zzz'));
        $runs->receiveArtifact($a, 'aaa');
        Storage::disk('local')->put('direct/b.png', 'bbb');
        $runs->markArtifactReceived($b, 'local', 'direct/b.png');

        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 2]])->assertOk();
        $runId = $created->json('data.run.id');
        $this->assertSame('aaa', $this->get("/api/runs/{$runId}/artifacts/{$a->public_id}")->streamedContent());
        $this->assertSame('bbb', $this->get("/api/runs/{$runId}/artifacts/{$b->public_id}")->streamedContent());
    }

    #[Test]
    public function a_runner_records_details_about_its_attempt(): void
    {
        $runId = $this->createRun()->json('data.run.id');
        $claim = $this->claim('runner-1', ['metadata' => ['version' => '1.4.0']]);
        $attempt = $claim->json('data.claims.0.attempt.id');
        $token = $claim->json('data.claims.0.lease_token');

        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/heartbeat", ['metadata' => ['browser' => 'chromium']]);
        $this->asAttempt($token)->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 1], 'metadata' => ['ms' => 900]]);

        $this->assertEqualsCanonicalizing(
            ['version' => '1.4.0', 'browser' => 'chromium', 'ms' => 900],
            $this->getJson("/api/runs/{$runId}/attempts")->json('data.0.metadata'),
        );
    }

    private function expectsMismatch(callable $call): void
    {
        try {
            $call();
            $this->fail('A mismatched file was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
