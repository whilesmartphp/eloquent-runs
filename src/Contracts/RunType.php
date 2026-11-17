<?php

namespace Whilesmart\Runs\Contracts;

interface RunType
{
    /** Validation rules for the input a run of this type is created with. */
    public function inputRules(): array;

    /** Validation rules for the result an attempt submits on completion. */
    public function resultRules(): array;

    /** Whether a failure with this error code may be tried again. */
    public function isRetryable(?string $errorCode): bool;
}
