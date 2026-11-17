<?php

namespace Whilesmart\Runs\Contracts;

use Whilesmart\Runs\Models\Run;
use Whilesmart\Runs\Models\RunAttempt;

/**
 * Implemented by run types that the Laravel queue executes. The returned
 * array is the run's result. Throw RunFailed to fail with an error code.
 */
interface HandlesRun
{
    public function handle(Run $run, RunAttempt $attempt): array;
}
