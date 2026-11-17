<?php

namespace Whilesmart\Runs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Whilesmart\Runs\Enums\AttemptStatus;
use Whilesmart\Runs\Models\Concerns\HasPublicId;
use Whilesmart\Runs\Support\RunModels;

class RunAttempt extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    protected $hidden = ['lease_token_hash'];

    protected $casts = [
        'status' => AttemptStatus::class,
        'number' => 'integer',
        'result' => 'array',
        'lease_expires_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'deadline_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return config('runs.attempts_table', 'work_run_attempts');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(RunModels::run(), 'run_id');
    }

    public function executor(): MorphTo
    {
        return $this->morphTo();
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(RunModels::artifact(), 'attempt_id');
    }

    public function holdsLease(string $token): bool
    {
        return $this->status === AttemptStatus::Running
            && $this->lease_token_hash !== null
            && hash_equals($this->lease_token_hash, hash('sha256', $token))
            && $this->lease_expires_at !== null
            && $this->lease_expires_at->isFuture();
    }
}
