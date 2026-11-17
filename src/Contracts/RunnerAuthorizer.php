<?php

namespace Whilesmart\Runs\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Whilesmart\Runs\Support\Runner;

interface RunnerAuthorizer
{
    /** Identify the machine making a claim, or null to refuse it. */
    public function identify(Request $request): ?Runner;

    /** Constrain claimable runs to those this runner may take. */
    public function scope(Builder $query, Runner $runner): Builder;
}
