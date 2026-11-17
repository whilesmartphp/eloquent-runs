<?php

namespace Tests\Fixtures;

use Whilesmart\Runs\Models\Run;
use Whilesmart\Runs\Models\RunAttempt;

class InvalidResultType extends AddNumbersType
{
    public function handle(Run $run, RunAttempt $attempt): array
    {
        return $attempt->number === 1 ? ['sum' => 'invalid'] : parent::handle($run, $attempt);
    }

    public function isRetryable(?string $errorCode): bool
    {
        return false;
    }
}
