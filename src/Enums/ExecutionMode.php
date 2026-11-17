<?php

namespace Whilesmart\Runs\Enums;

enum ExecutionMode: string
{
    // The work happens elsewhere, such as in CI, and its result is uploaded.
    case Reported = 'reported';
    // A machine claims the work over HTTP.
    case Pull = 'pull';
    // A Laravel queued job runs the type's handler.
    case Queue = 'queue';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
