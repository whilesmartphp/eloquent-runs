<?php

namespace Whilesmart\Runs\Support;

use Illuminate\Http\JsonResponse;
use Whilesmart\Runs\Contracts\ResponseFormatter;

class JsonResponseFormatter implements ResponseFormatter
{
    public function success(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    public function error(string $message, int $status, ?string $code = null): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ], fn ($value) => $value !== null), $status);
    }
}
