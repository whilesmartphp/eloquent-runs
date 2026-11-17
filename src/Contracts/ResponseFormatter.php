<?php

namespace Whilesmart\Runs\Contracts;

use Illuminate\Http\JsonResponse;

interface ResponseFormatter
{
    public function success(mixed $data, int $status = 200): JsonResponse;

    public function error(string $message, int $status, ?string $code = null): JsonResponse;
}
