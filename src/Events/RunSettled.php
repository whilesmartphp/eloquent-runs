<?php

namespace Whilesmart\Runs\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Whilesmart\Runs\Models\Run;

class RunSettled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Run $run) {}
}
