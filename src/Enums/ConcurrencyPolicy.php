<?php

namespace Whilesmart\Runs\Enums;

/** What a new run does when another run of the same owner shares its concurrency key. */
enum ConcurrencyPolicy: string
{
    // Run alongside the others.
    case Parallel = 'parallel';
    // Wait until every earlier run with the key has finished.
    case Queue = 'queue';
    // Refuse to start while another run with the key is unfinished.
    case Reject = 'reject';
    // Cancel the unfinished runs with the key and start.
    case Supersede = 'supersede';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
