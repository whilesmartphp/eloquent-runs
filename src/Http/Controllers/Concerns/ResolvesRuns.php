<?php

namespace Whilesmart\Runs\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Whilesmart\Runs\Contracts\ResponseFormatter;
use Whilesmart\Runs\Http\Resources\ArtifactResource;
use Whilesmart\Runs\Http\Resources\AttemptResource;
use Whilesmart\Runs\Http\Resources\RunResource;
use Whilesmart\Runs\Models\Run;
use Whilesmart\Runs\Models\RunAttempt;
use Whilesmart\Runs\Support\RunModels;

trait ResolvesRuns
{
    protected function findRun(string $publicId): Run
    {
        return RunModels::run()::query()->where('public_id', $publicId)->firstOrFail();
    }

    protected function findAttempt(string $publicId): RunAttempt
    {
        return RunModels::attempt()::query()->where('public_id', $publicId)->firstOrFail();
    }

    /** @return class-string<JsonResource> */
    protected function resource(string $name): string
    {
        return config("runs.resources.{$name}", match ($name) {
            'attempt' => AttemptResource::class,
            'artifact' => ArtifactResource::class,
            default => RunResource::class,
        });
    }

    protected function respond(mixed $data, int $status = 200): JsonResponse
    {
        return app(ResponseFormatter::class)->success($data, $status);
    }
}
