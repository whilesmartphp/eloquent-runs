<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Whilesmart\Runs\Contracts\RunnerAuthorizer;
use Whilesmart\Runs\Support\Runner;

/** Accepts the bearer token "runner-<owner id>" and lets it take that owner's runs. */
class TokenRunnerAuthorizer implements RunnerAuthorizer
{
    public function identify(Request $request): ?Runner
    {
        $token = (string) $request->bearerToken();

        return str_starts_with($token, 'runner-') ? new Runner($token, 'App\\Models\\Runner', substr($token, 7)) : null;
    }

    public function scope(Builder $query, Runner $runner): Builder
    {
        return $query->where('owner_id', $runner->id);
    }
}
