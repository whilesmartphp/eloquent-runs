<?php

namespace Whilesmart\Runs\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Whilesmart\Runs\Database\Factories\RunFactory;
use Whilesmart\Runs\Enums\ExecutionMode;
use Whilesmart\Runs\Enums\RunStatus;
use Whilesmart\Runs\Models\Concerns\HasPublicId;
use Whilesmart\Runs\Support\RunModels;

class Run extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => RunStatus::class,
        'execution_mode' => ExecutionMode::class,
        'input' => 'array',
        'result' => 'array',
        'progress' => 'array',
        'metadata' => 'array',
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
        'lease_seconds' => 'integer',
        'attempt_timeout_seconds' => 'integer',
        'available_at' => 'datetime',
        'deadline_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('runs.runs_table', 'work_runs');
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function trigger(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(RunModels::attempt(), 'run_id');
    }

    public function acceptedAttempt(): BelongsTo
    {
        return $this->belongsTo(RunModels::attempt(), 'accepted_attempt_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(RunModels::artifact(), 'run_id');
    }

    public function logChunks(): HasMany
    {
        return $this->hasMany(RunModels::logChunk(), 'run_id');
    }

    public function canRetry(): bool
    {
        return $this->execution_mode !== ExecutionMode::Reported
            && $this->cancel_requested_at === null
            && $this->attempt_count < $this->max_attempts
            && ($this->deadline_at === null || $this->deadline_at->isFuture());
    }

    public function scopeBeforeDeadline(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('deadline_at')->orWhere('deadline_at', '>', now()));
    }

    protected static function newFactory(): RunFactory
    {
        return RunFactory::new();
    }
}
