<?php

namespace Whilesmart\Runs\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Whilesmart\Runs\Contracts\ResponseFormatter;

/** The request no longer matches the run's state, such as a lease that expired. */
class RunConflict extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return app(ResponseFormatter::class)->error($this->getMessage(), 409, $this->errorCode);
    }
}
