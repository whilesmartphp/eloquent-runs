<?php

namespace Whilesmart\Runs\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Whilesmart\Runs\Contracts\ResponseFormatter;

class InvalidLeaseToken extends RuntimeException
{
    public function render(): JsonResponse
    {
        return app(ResponseFormatter::class)->error('The lease token does not match this attempt.', 401, 'invalid_token');
    }
}
