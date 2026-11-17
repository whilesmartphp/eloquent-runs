<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\AddNumbersType;
use Tests\TestCase;
use Whilesmart\Runs\Events\RunRetryScheduled;
use Whilesmart\Runs\Events\RunSettled;

class QueueRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AddNumbersType::$calls = 0;
    }

    #[Test]
    public function the_queue_runs_the_types_handler(): void
    {
        Event::fake([RunSettled::class]);

        $runId = $this->createRun(['type' => 'add', 'execution_mode' => 'queue', 'input' => ['numbers' => [2, 3]]])
            ->assertCreated()
            ->json('data.run.id');

        $this->getJson("/api/runs/{$runId}")
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.result.sum', 5);
        $this->getJson("/api/runs/{$runId}/attempts")->assertJsonPath('data.0.executor_name', 'queue');
        Event::assertDispatched(RunSettled::class);
    }

    #[Test]
    public function a_retryable_failure_runs_again_as_a_new_attempt(): void
    {
        Event::fake([RunRetryScheduled::class]);

        $runId = $this->createRun([
            'type' => 'add',
            'execution_mode' => 'queue',
            'max_attempts' => 3,
            'input' => ['numbers' => [1, 1], 'fail_until' => 2],
        ])->json('data.run.id');

        $this->getJson("/api/runs/{$runId}")->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.attempt_count', 3);
        $this->assertSame(3, AddNumbersType::$calls);
        Event::assertDispatchedTimes(RunRetryScheduled::class, 2);
    }

    #[Test]
    public function a_type_without_a_handler_fails_on_the_queue(): void
    {
        $runId = $this->createRun(['execution_mode' => 'queue', 'max_attempts' => 3])->json('data.run.id');

        $this->getJson("/api/runs/{$runId}")->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'no_handler');
    }
}
