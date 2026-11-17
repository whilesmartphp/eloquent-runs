<?php

namespace Whilesmart\Runs\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Whilesmart\Runs\Contracts\ResponseFormatter;

/** The request no longer matches the run's state, such as a lease that expired. */
class RunConflict extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $response = app(ResponseFormatter::class)->error($this->getMessage(), 409, $this->errorCode);

        if ($this->details !== []) {
            $response->setData([...(array) $response->getData(true), 'details' => $this->details]);
        }

        return $response;
    }
}
