<?php

namespace Whilesmart\Runs\Enums;

enum AttemptStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    // Ended so the run could wait; the run continues in a later attempt.
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
