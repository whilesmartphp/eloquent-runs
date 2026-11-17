<?php

namespace Whilesmart\Runs\Authorizers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Whilesmart\Runs\Contracts\RunnerAuthorizer;
use Whilesmart\Runs\Support\Runner;

class DenyAllRunnerAuthorizer implements RunnerAuthorizer
{
    public function identify(Request $request): ?Runner
    {
        return null;
    }

    public function scope(Builder $query, Runner $runner): Builder
    {
        return $query->whereRaw('0 = 1');
    }
}
