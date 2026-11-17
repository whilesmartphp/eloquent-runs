<?php

namespace Whilesmart\Runs\Exceptions;

use RuntimeException;

class RunFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
