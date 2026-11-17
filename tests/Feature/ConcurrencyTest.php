<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    #[Test]
    public function reject_refuses_a_run_while_another_with_the_key_is_unfinished(): void
    {
        $first = $this->createRun(['concurrency_key' => 'app-7', 'concurrency_policy' => 'reject'])->assertCreated()->json('data.run.id');

        $this->createRun(['concurrency_key' => 'app-7', 'concurrency_policy' => 'reject'])
            ->assertStatus(409)->assertJsonPath('code', 'concurrency_busy');
        $this->createRun(['concurrency_key' => 'app-7', 'concurrency_policy' => 'reject', 'owner_id' => 2])->assertCreated();

        $this->postJson("/api/runs/{$first}/cancel");
        $this->createRun(['concurrency_key' => 'app-7', 'concurrency_policy' => 'reject'])->assertCreated();
    }

    #[Test]
    public function supersede_cancels_older_runs_with_the_key(): void
    {
        $running = $this->createRun(['concurrency_key' => 'pr-12'])->json('data.run.id');
        $queued = $this->createRun(['concurrency_key' => 'pr-12'])->json('data.run.id');
        $this->claim();

        $this->createRun(['concurrency_key' => 'pr-12', 'concurrency_policy' => 'supersede'])->assertCreated();

        $this->getJson("/api/runs/{$queued}")->assertJsonPath('data.status', 'cancelled');
        $this->getJson("/api/runs/{$running}")->assertJsonPath('data.status', 'cancelling');
    }

    #[Test]
    public function supersede_closes_an_unfinished_upload(): void
    {
        $old = $this->createRun(['execution_mode' => 'reported', 'concurrency_key' => 'pr-12', 'concurrency_policy' => 'supersede']);
        $this->createRun(['execution_mode' => 'reported', 'concurrency_key' => 'pr-12', 'concurrency_policy' => 'supersede'])->assertCreated();

        $this->getJson('/api/runs/'.$old->json('data.run.id'))->assertJsonPath('data.status', 'cancelled');
        $this->asAttempt($old->json('data.upload.lease_token'))
            ->postJson('/api/runner/attempts/'.$old->json('data.upload.attempt.id').'/complete', ['result' => ['images' => 1]])
            ->assertStatus(409);
    }

    #[Test]
    public function queued_runs_with_the_key_are_claimed_one_at_a_time_in_order(): void
    {
        $first = $this->createRun(['concurrency_key' => 'deploy', 'concurrency_policy' => 'queue'])->json('data.run.id');
        $this->travel(1)->seconds();
        $second = $this->createRun(['concurrency_key' => 'deploy', 'concurrency_policy' => 'queue'])->json('data.run.id');
        $other = $this->createRun(['concurrency_key' => 'other', 'concurrency_policy' => 'queue'])->json('data.run.id');

        $claims = $this->claim('runner-1', ['limit' => 5])->assertOk()->json('data.claims');
        $this->assertEqualsCanonicalizing([$first, $other], array_column(array_column($claims, 'run'), 'id'));
        $this->claim()->assertNoContent();

        $taken = collect($claims)->firstWhere('run.id', $first);
        $this->asAttempt($taken['lease_token'])
            ->postJson("/api/runner/attempts/{$taken['attempt']['id']}/complete", ['result' => ['images' => 1]]);

        $this->claim()->assertOk()->assertJsonPath('data.claims.0.run.id', $second);
    }

    #[Test]
    public function a_queue_run_starts_when_the_run_ahead_of_it_finishes(): void
    {
        $this->createRun(['concurrency_key' => 'deploy', 'concurrency_policy' => 'queue']);
        $ahead = $this->claim();
        $this->travel(1)->seconds();

        $behind = $this->createRun([
            'type' => 'add',
            'execution_mode' => 'queue',
            'input' => ['numbers' => [2, 2]],
            'concurrency_key' => 'deploy',
            'concurrency_policy' => 'queue',
        ])->json('data.run.id');
        $this->getJson("/api/runs/{$behind}")->assertJsonPath('data.status', 'queued');

        $this->asAttempt($ahead->json('data.claims.0.lease_token'))
            ->postJson('/api/runner/attempts/'.$ahead->json('data.claims.0.attempt.id').'/complete', ['result' => ['images' => 1]]);

        $this->getJson("/api/runs/{$behind}")->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.result.sum', 4);
    }

    #[Test]
    public function a_reported_run_cannot_wait_its_turn(): void
    {
        $this->createRun(['execution_mode' => 'reported', 'concurrency_key' => 'k', 'concurrency_policy' => 'queue'])
            ->assertUnprocessable()->assertJsonValidationErrors('concurrency_policy');
    }
}
