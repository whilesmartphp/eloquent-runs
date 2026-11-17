<?php

namespace Whilesmart\Runs\Support;

use Whilesmart\Runs\Models\Run;

class CreatedRun
{
    public function __construct(
        public readonly Run $run,
        public readonly bool $created,
        public readonly ?StartedAttempt $upload = null,
    ) {}
}
