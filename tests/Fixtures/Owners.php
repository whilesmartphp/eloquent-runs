<?php

namespace Tests\Fixtures;

/** Test owner ids: small integers, or a fixed UUID for each when UUID keys are on. */
class Owners
{
    public static function key(int|string $id): int|string
    {
        return config('runs.uuids') && is_numeric($id)
            ? sprintf('00000000-0000-4000-8000-%012d', $id)
            : $id;
    }
}
