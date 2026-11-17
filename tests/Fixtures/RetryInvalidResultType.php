<?php

namespace Tests\Fixtures;

class RetryInvalidResultType extends InvalidResultType
{
    public function isRetryable(?string $errorCode): bool
    {
        return $errorCode === 'invalid_result';
    }
}
