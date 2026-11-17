<?php

namespace Whilesmart\Runs\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Whilesmart\Runs\Models\RunAttempt;

class RunClaimed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly RunAttempt $attempt) {}
}
