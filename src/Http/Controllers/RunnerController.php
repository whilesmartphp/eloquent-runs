<?php

namespace Whilesmart\Runs\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Whilesmart\Runs\Contracts\ResponseFormatter;
use Whilesmart\Runs\Contracts\RunnerAuthorizer;
use Whilesmart\Runs\Http\Controllers\Concerns\ResolvesRuns;
use Whilesmart\Runs\Services\RunService;

class RunnerController extends Controller
{
    use ResolvesRuns;

    public function __construct(
        private readonly RunService $runs,
        private readonly RunnerAuthorizer $authorizer,
    ) {}

    public function claim(Request $request): JsonResponse|Response
    {
        $runner = $this->authorizer->identify($request);
        if (! $runner) {
            return app(ResponseFormatter::class)->error('This machine may not claim runs.', 401, 'runner_unknown');
        }

        $validated = $request->validate([
            'types' => ['nullable', 'array'],
            'types.*' => ['string'],
        ]);

        $started = $this->runs->claim($runner, $validated['types'] ?? null);
        if (! $started) {
            return response()->noContent();
        }

        $run = $started->attempt->run;

        return $this->respond([
            'run' => new ($this->resource('run'))($run),
            'attempt' => new ($this->resource('attempt'))($started->attempt),
            'lease_token' => $started->leaseToken,
        ]);
    }
}
