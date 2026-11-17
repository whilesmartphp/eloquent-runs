<?php

namespace Whilesmart\Runs\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Whilesmart\Runs\Models\RunArtifact;

class ArtifactReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly RunArtifact $artifact) {}
}
