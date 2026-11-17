<?php

namespace Whilesmart\Runs\Support;

use Whilesmart\Runs\Models\RunAttempt;

class StartedAttempt
{
    /** The token is returned once and only its hash is stored. */
    public function __construct(
        public readonly RunAttempt $attempt,
        public readonly string $leaseToken,
    ) {}
}
