<?php

namespace Whilesmart\Runs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Whilesmart\Runs\Support\RunModels;

class RunLogChunk extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'sequence' => 'integer',
        'occurred_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('runs.log_chunks_table', 'work_run_log_chunks');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(RunModels::attempt(), 'attempt_id');
    }
}
