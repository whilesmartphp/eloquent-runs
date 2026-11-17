<?php

namespace Whilesmart\Runs\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Validation\ValidationException;
use Throwable;
use Whilesmart\Runs\Contracts\HandlesRun;
use Whilesmart\Runs\Exceptions\RunConflict;
use Whilesmart\Runs\Exceptions\RunFailed;
use Whilesmart\Runs\Exceptions\RunWaits;
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

    public function __construct(public readonly int|string $runId) {}

    public function handle(RunService $runs, RunTypeRegistry $types): void
    {
        $run = RunModels::run()::query()->find($this->runId);
        if (! $run || ! $started = $runs->startQueued($run)) {
            return;
        }

        try {
            $handler = $types->get($run->type);
            if (! $handler instanceof HandlesRun) {
                $runs->fail($started->attempt, $started->leaseToken, 'no_handler', "Run type [{$run->type}] cannot run on the queue.", false);

                return;
            }
            try {
                $result = $handler->handle($run->fresh(), $started->attempt);
            } catch (RunWaits $e) {
                $runs->wait($started->attempt, $started->leaseToken, $e->reason, $e->state, $e->resumeAt);

                return;
            } catch (RunFailed $e) {
                $runs->fail($started->attempt, $started->leaseToken, $e->errorCode, $e->getMessage());

                return;
            } catch (Throwable $e) {
                report($e);
                $runs->fail($started->attempt, $started->leaseToken, 'exception', $e->getMessage());

                return;
            }
            try {
                $runs->complete($started->attempt, $started->leaseToken, $result);
            } catch (ValidationException) {
                $runs->fail($started->attempt, $started->leaseToken, 'invalid_result', 'The result did not match the run type.');
            }
        } catch (RunConflict $exception) {
            if ($exception->errorCode !== 'lease_lost') {
                throw $exception;
            }
        }
    }
}
