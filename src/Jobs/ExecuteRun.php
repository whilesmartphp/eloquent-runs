<?php

namespace Whilesmart\Runs\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;
use Whilesmart\Runs\Contracts\HandlesRun;
use Whilesmart\Runs\Exceptions\RunFailed;
use Whilesmart\Runs\Services\RunService;
use Whilesmart\Runs\Support\RunModels;
use Whilesmart\Runs\Support\RunTypeRegistry;

/**
 * Runs one attempt of a queue-mode run. Retries are scheduled by the run
 * service as new attempts, so this job never asks the queue to retry it.
 */
class ExecuteRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $runId) {}

    public function handle(RunService $runs, RunTypeRegistry $types): void
    {
        $run = RunModels::run()::query()->find($this->runId);
        if (! $run || ! $started = $runs->startQueued($run)) {
            return;
        }

        $handler = $types->get($run->type);
        if (! $handler instanceof HandlesRun) {
            $runs->fail($started->attempt, $started->leaseToken, 'no_handler', "Run type [{$run->type}] cannot run on the queue.", false);

            return;
        }

        try {
            $result = $handler->handle($run->fresh(), $started->attempt);
        } catch (RunFailed $e) {
            $runs->fail($started->attempt, $started->leaseToken, $e->errorCode, $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $runs->fail($started->attempt, $started->leaseToken, 'exception', $e->getMessage());

            return;
        }

        $runs->complete($started->attempt, $started->leaseToken, $result);
    }
}
