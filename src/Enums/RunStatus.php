<?php

namespace Whilesmart\Runs\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Waiting = 'waiting';
    case Cancelling = 'cancelling';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case TimedOut = 'timed_out';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Statuses that hold a concurrency key. */
    public static function unfinished(): array
    {
        return [self::Queued, self::Running, self::Waiting, self::Cancelling];
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled, self::TimedOut], true);
    }
}
