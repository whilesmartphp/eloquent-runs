<?php

namespace Whilesmart\Runs\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Whilesmart\Runs\Support\RunModels;

trait HasRuns
{
    public function runs(): MorphMany
    {
        return $this->morphMany(RunModels::run(), 'owner');
    }
}
