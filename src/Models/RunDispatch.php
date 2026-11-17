<?php

namespace Whilesmart\Runs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Whilesmart\Runs\Support\RunModels;

class RunDispatch extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['dispatch_token', 'last_error'];

    protected $casts = ['next_dispatch_at' => 'datetime', 'dispatch_attempts' => 'integer'];

    public function getTable(): string
    {
        return config('runs.dispatches_table', 'work_run_dispatches');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(RunModels::run(), 'run_id');
    }
}
