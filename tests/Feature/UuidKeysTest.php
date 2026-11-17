<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UuidKeysTest extends TestCase
{
    private const OWNER_ID = '9b2c1e0a-6a4f-4f8e-9d3a-0c5e7f1a2b3c';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('runs.uuids', true);
    }

    #[Test]
    public function a_uuid_owner_runs_work_end_to_end(): void
    {
        Storage::fake('local');
        $runId = $this->createRun(['owner_id' => self::OWNER_ID, 'max_attempts' => 2])->assertCreated()->json('data.run.id');

        $first = $this->claim('runner-'.self::OWNER_ID);
        $this->asAttempt($first->json('data.claims.0.lease_token'))
            ->postJson('/api/runner/attempts/'.$first->json('data.claims.0.attempt.id').'/fail', ['error_code' => 'browser_crashed']);

        $second = $this->claim('runner-'.self::OWNER_ID)->assertJsonPath('data.claims.0.attempt.number', 2);
        $attempt = $second->json('data.claims.0.attempt.id');
        $as = fn () => $this->asAttempt($second->json('data.claims.0.lease_token'));
        $as()->postJson("/api/runner/attempts/{$attempt}/logs", ['chunks' => [['sequence' => 0, 'content' => 'ok']]]);
        $as()->post("/api/runner/attempts/{$attempt}/artifacts", [
            'file' => UploadedFile::fake()->createWithContent('a.png', 'bytes'),
            'path' => 'a.png',
        ], ['Accept' => 'application/json'])->assertCreated();
        $as()->postJson("/api/runner/attempts/{$attempt}/complete", ['result' => ['images' => 1]])->assertJsonPath('data.status', 'succeeded');

        $this->getJson("/api/runs/{$runId}/artifacts")->assertJsonCount(1, 'data');
        $this->getJson("/api/runs/{$runId}/logs")->assertJsonPath('data.chunks.0.content', 'ok');

        $row = DB::table('work_runs')->first();
        $this->assertTrue(Str::isUuid($row->id));
        $this->assertSame(self::OWNER_ID, $row->owner_id);
        $this->assertTrue(Str::isUuid($row->accepted_attempt_id));
        $this->assertTrue(Str::isUuid(DB::table('work_run_artifacts')->value('attempt_id')));
    }
}
