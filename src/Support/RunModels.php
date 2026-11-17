<?php

namespace Whilesmart\Runs\Support;

use Whilesmart\Runs\Models\Run;
use Whilesmart\Runs\Models\RunArtifact;
use Whilesmart\Runs\Models\RunAttempt;
use Whilesmart\Runs\Models\RunLogChunk;

class RunModels
{
    /** @return class-string<Run> */
    public static function run(): string
    {
        return config('runs.models.run', Run::class);
    }

    /** @return class-string<RunAttempt> */
    public static function attempt(): string
    {
        return config('runs.models.attempt', RunAttempt::class);
    }

    /** @return class-string<RunArtifact> */
    public static function artifact(): string
    {
        return config('runs.models.artifact', RunArtifact::class);
    }

    /** @return class-string<RunLogChunk> */
    public static function logChunk(): string
    {
        return config('runs.models.log_chunk', RunLogChunk::class);
    }
}
