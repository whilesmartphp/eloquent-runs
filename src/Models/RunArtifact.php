<?php

namespace Whilesmart\Runs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Whilesmart\Runs\Models\Concerns\HasPublicId;
use Whilesmart\Runs\Support\RunModels;

class RunArtifact extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected $casts = [
        'size' => 'integer',
        'received_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function getTable(): string
    {
        return config('runs.artifacts_table', 'work_run_artifacts');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(RunModels::run(), 'run_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(RunModels::attempt(), 'attempt_id');
    }
}
