<?php

namespace Whilesmart\Runs\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;
use Whilesmart\Runs\Contracts\RunnerAuthorizer;
use Whilesmart\Runs\Enums\AttemptStatus;
use Whilesmart\Runs\Enums\ConcurrencyPolicy;
use Whilesmart\Runs\Enums\ExecutionMode;
use Whilesmart\Runs\Enums\RunStatus;
use Whilesmart\Runs\Events\ArtifactReceived;
use Whilesmart\Runs\Events\RunCancellationRequested;
use Whilesmart\Runs\Events\RunClaimed;
use Whilesmart\Runs\Events\RunCreated;
use Whilesmart\Runs\Events\RunResumed;
use Whilesmart\Runs\Events\RunRetryScheduled;
use Whilesmart\Runs\Events\RunSettled;
use Whilesmart\Runs\Events\RunWaiting;
use Whilesmart\Runs\Exceptions\InvalidLeaseToken;
use Whilesmart\Runs\Exceptions\RunConflict;
use Whilesmart\Runs\Jobs\ExecuteRun;
use Whilesmart\Runs\Models\Run;
use Whilesmart\Runs\Models\RunArtifact;
use Whilesmart\Runs\Models\RunAttempt;
use Whilesmart\Runs\Support\CreatedRun;
use Whilesmart\Runs\Support\RunModels;
use Whilesmart\Runs\Support\Runner;
use Whilesmart\Runs\Support\RunTypeRegistry;
use Whilesmart\Runs\Support\StartedAttempt;

class RunService
{
    public function __construct(
        private readonly RunTypeRegistry $types,
        private readonly RunnerAuthorizer $runners,
    ) {}

    /**
     * Create a run. A repeated idempotency key for the same owner returns the
     * existing run. A reported run starts with an upload attempt whose token
     * lets the reporter attach files and settle it.
     */
    public function create(array $attributes): CreatedRun
    {
        $type = $this->types->get($attributes['type']);
        Validator::make($attributes, [
            'request_fingerprint' => ['nullable', 'string', 'max:255'],
            'concurrency_key' => ['nullable', 'string', 'max:200'],
            'concurrency_policy' => ['nullable', Rule::in(ConcurrencyPolicy::values())],
        ])->validate();
        Validator::make(['input' => $attributes['input'] ?? []], $this->prefix('input', $type->inputRules()))->validate();

        if ($existing = $this->findByIdempotencyKey($attributes)) {
            $this->assertFingerprint($existing, $attributes['request_fingerprint'] ?? null);
            $this->ensureQueueDelivery($existing);

            return new CreatedRun($existing, false);
        }

        $mode = ExecutionMode::from($attributes['execution_mode'] ?? ExecutionMode::Pull->value);
        $policy = ConcurrencyPolicy::from($attributes['concurrency_policy'] ?? ConcurrencyPolicy::Parallel->value);
        if ($mode === ExecutionMode::Reported && $policy === ConcurrencyPolicy::Queue) {
            throw ValidationException::withMessages(['concurrency_policy' => 'A reported run starts at once, so it cannot wait its turn.']);
        }

        $defaults = (array) config('runs.defaults', []);
        $modelClass = RunModels::run();

        try {
            return DB::transaction(function () use ($attributes, $mode, $policy, $defaults, $modelClass) {
                if (! empty($attributes['concurrency_key'])) {
                    $this->applyPolicy($policy, $attributes);
                }

                $run = $modelClass::create([
                    ...$attributes,
                    'input' => $attributes['input'] ?? [],
                    'execution_mode' => $mode,
                    'concurrency_policy' => $policy,
                    'status' => $mode === ExecutionMode::Reported ? RunStatus::Running : RunStatus::Queued,
                    'max_attempts' => $mode === ExecutionMode::Reported ? 1 : ($attributes['max_attempts'] ?? $defaults['max_attempts'] ?? 1),
                    'lease_seconds' => $attributes['lease_seconds'] ?? $defaults['lease_seconds'] ?? 90,
                    'attempt_timeout_seconds' => $attributes['attempt_timeout_seconds'] ?? $defaults['attempt_timeout_seconds'] ?? 3600,
                    'available_at' => now(),
                ]);

                $upload = null;
                if ($mode === ExecutionMode::Reported) {
                    $upload = $this->startAttempt($run, null, $run->attempt_timeout_seconds);
                }

                RunCreated::dispatch($run);

                if ($mode === ExecutionMode::Queue) {
                    $this->dispatchQueued($run);
                }

                return new CreatedRun($run->fresh(), true, $upload);
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->findByIdempotencyKey($attributes);
            if ($existing) {
                $this->assertFingerprint($existing, $attributes['request_fingerprint'] ?? null);
                $this->ensureQueueDelivery($existing);
            }

            return $existing ? new CreatedRun($existing, false) : throw $e;
        }
    }

    /**
     * Hand up to $limit available pull runs this runner may take to it,
     * oldest first. Empty when there is nothing to do.
     *
     * @return array<int, StartedAttempt>
     */
    public function claim(Runner $runner, ?array $types = null, int $limit = 1, ?array $metadata = null): array
    {
        $accepted = $runner->types === null ? $types : array_values(array_intersect($types ?? $runner->types, $runner->types));
        $claims = [];
        $misses = 0;

        while (count($claims) < $limit && $misses < 5) {
            $query = RunModels::run()::query()
                ->where('execution_mode', ExecutionMode::Pull)
                ->where('status', RunStatus::Queued)
                ->where('available_at', '<=', now())
                ->beforeDeadline()
                ->notBlocked()
                ->when($accepted !== null, fn ($q) => $q->whereIn('type', $accepted))
                ->orderBy('available_at')
                ->orderBy('id');

            $candidate = $this->runners->scope($query, $runner)->first();
            if (! $candidate) {
                break;
            }

            $started = DB::transaction(function () use ($candidate, $runner, $metadata) {
                if ($this->isBlocked($candidate)) {
                    return null;
                }

                $taken = RunModels::run()::query()
                    ->whereKey($candidate->getKey())
                    ->where('status', RunStatus::Queued)
                    ->where('available_at', '<=', now())
                    ->beforeDeadline()
                    ->update(['status' => RunStatus::Running]);

                return $taken === 1 ? $this->startAttempt($candidate->fresh(), $runner, $candidate->lease_seconds, $metadata) : null;
            });

            if ($started) {
                RunClaimed::dispatch($started->attempt);
                $claims[] = $started;
            } else {
                $misses++;
            }
        }

        return $claims;
    }

    public function heartbeat(RunAttempt $attempt, string $token, ?array $progress = null, ?array $metadata = null): RunAttempt
    {
        return DB::transaction(function () use ($attempt, $token, $progress, $metadata) {
            $attempt = $this->leased($attempt, $token);
            $run = $attempt->run;
            $extended = now()->addSeconds($run->lease_seconds);

            $attempt->update([
                'heartbeat_at' => now(),
                'lease_expires_at' => $this->earliest(
                    $attempt->lease_expires_at->max($extended),
                    $attempt->deadline_at,
                ),
                'metadata' => $this->merged($attempt->metadata, $metadata),
            ]);

            if ($progress !== null) {
                $run->update(['progress' => $progress]);
            }

            return $attempt;
        });
    }

    /**
     * Append log lines. A chunk whose sequence number was already stored is
     * skipped, so a worker can resend a batch after a network failure.
     *
     * @param  array<int, array{sequence: int, content: string, stream?: string, occurred_at?: string}>  $chunks
     */
    public function appendLogs(RunAttempt $attempt, string $token, array $chunks): int
    {
        $attempt = $this->leased($attempt, $token);

        $rows = array_map(fn (array $chunk) => [
            'run_id' => $attempt->run_id,
            'attempt_id' => $attempt->getKey(),
            'sequence' => (int) $chunk['sequence'],
            'stream' => $chunk['stream'] ?? 'stdout',
            'content' => $chunk['content'],
            'occurred_at' => isset($chunk['occurred_at']) ? Carbon::parse($chunk['occurred_at']) : null,
            'created_at' => now(),
        ], $chunks);

        return RunModels::logChunk()::query()->insertOrIgnore($rows);
    }

    /**
     * Declare the files an attempt will deliver. Completion is refused until
     * each one has arrived. Declaring the same file again is allowed; a
     * different file at a declared path is a conflict.
     *
     * @param  array<int, array{path: string, media_type: string, size: int, sha256: string, type?: string|null, metadata?: array|null}>  $files
     * @return EloquentCollection<int, RunArtifact>
     */
    public function declareArtifacts(RunAttempt $attempt, string $token, array $files): EloquentCollection
    {
        return DB::transaction(function () use ($attempt, $token, $files) {
            $attempt = $this->leased($attempt, $token);
            $max = (int) config('runs.artifacts.max_bytes');

            return new EloquentCollection(array_map(function (array $file) use ($attempt, $max) {
                if ((int) $file['size'] > $max) {
                    throw ValidationException::withMessages(['files' => "{$file['path']} is larger than artifacts may be."]);
                }

                $sha256 = strtolower($file['sha256']);
                $existing = $this->artifactAt($attempt, $file['path']);
                if ($existing) {
                    if ($existing->sha256 !== $sha256 || $existing->size !== (int) $file['size'] || $existing->media_type !== $file['media_type']) {
                        throw new RunConflict('artifact_exists', "This attempt already declared a different file at {$file['path']}.");
                    }

                    return $existing;
                }

                return RunModels::artifact()::create([
                    'run_id' => $attempt->run_id,
                    'attempt_id' => $attempt->getKey(),
                    'path' => $file['path'],
                    'path_hash' => hash('sha256', $file['path']),
                    'type' => $file['type'] ?? null,
                    'media_type' => $file['media_type'],
                    'size' => (int) $file['size'],
                    'sha256' => $sha256,
                    'metadata' => $file['metadata'] ?? null,
                ]);
            }, array_values($files)));
        });
    }

    /** Upload a file for an attempt. A declared file must match its declaration. */
    public function storeArtifact(
        RunAttempt $attempt,
        string $token,
        UploadedFile $file,
        string $path,
        ?string $type = null,
        ?string $sha256 = null,
        ?array $metadata = null,
    ): RunArtifact {
        $attempt = $this->leased($attempt, $token);

        $size = (int) $file->getSize();
        if ($size > (int) config('runs.artifacts.max_bytes')) {
            throw ValidationException::withMessages(['file' => 'The file is larger than artifacts may be.']);
        }

        $actual = hash_file('sha256', $file->getRealPath());
        if ($sha256 !== null && ! hash_equals(strtolower($sha256), $actual)) {
            throw ValidationException::withMessages(['sha256' => 'The file does not match its checksum.']);
        }

        if ($existing = $this->artifactAt($attempt, $path)) {
            if ($existing->received_at === null) {
                return $this->receiveArtifact($existing, $file->get());
            }
            if ($existing->sha256 === $actual) {
                return $existing;
            }

            throw new RunConflict('artifact_exists', 'This attempt already stored a different file at that path.');
        }

        $pathHash = hash('sha256', $path);
        [$disk, $storageKey] = $this->put($attempt, $pathHash, $actual, $file);

        try {
            $artifact = RunModels::artifact()::create([
                'run_id' => $attempt->run_id,
                'attempt_id' => $attempt->getKey(),
                'path' => $path,
                'path_hash' => $pathHash,
                'type' => $type,
                'media_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size' => $size,
                'sha256' => $actual,
                'disk' => $disk,
                'storage_key' => $storageKey,
                'received_at' => now(),
                'metadata' => $metadata,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $winner = $this->artifactAt($attempt, $path);

            if ($winner?->sha256 === $actual) {
                return $winner;
            }

            Storage::disk($disk)->delete($storageKey);

            throw new RunConflict('artifact_exists', 'This attempt already stored a different file at that path.');
        }

        ArtifactReceived::dispatch($artifact);

        return $artifact;
    }

    /**
     * Store the bytes of a declared file. For hosts that receive uploads
     * through their own routes; the contents must match the declaration.
     */
    public function receiveArtifact(RunArtifact $artifact, string $contents): RunArtifact
    {
        if (strlen($contents) !== $artifact->size || ! hash_equals($artifact->sha256, hash('sha256', $contents))) {
            throw ValidationException::withMessages(['file' => 'The file does not match its declared size and checksum.']);
        }

        $attempt = $this->receiving($artifact);
        if ($artifact->received_at !== null) {
            return $artifact;
        }

        [$disk, $storageKey] = $this->put($attempt, $artifact->path_hash, $artifact->sha256, $contents);

        return $this->markReceived($artifact, $disk, $storageKey);
    }

    /**
     * Record that a declared file was stored by the host itself, for example
     * uploaded straight to object storage. The host vouches for its contents.
     */
    public function markArtifactReceived(RunArtifact $artifact, string $disk, string $storageKey): RunArtifact
    {
        $this->receiving($artifact);

        return $artifact->received_at !== null ? $artifact : $this->markReceived($artifact, $disk, $storageKey);
    }

    public function complete(RunAttempt $attempt, string $token, array $result, ?array $metadata = null): Run
    {
        return DB::transaction(function () use ($attempt, $token, $result, $metadata) {
            $attempt = $this->locked($attempt);

            if ($attempt->status === AttemptStatus::Succeeded) {
                $this->assertToken($attempt, $token);
                if ($attempt->result == $result) {
                    return $attempt->run;
                }

                throw new RunConflict('already_completed', 'This attempt already completed with a different result.');
            }

            $attempt = $this->leased($attempt, $token);
            $run = $attempt->run;

            $missing = $attempt->artifacts()->whereNull('received_at')->orderBy('path')->pluck('path');
            if ($missing->isNotEmpty()) {
                throw new RunConflict('artifacts_missing', 'Declared files have not arrived.', ['paths' => $missing->all()]);
            }

            $rules = $this->types->get($run->type)->resultRules();
            Validator::make(['result' => $result], $this->prefix('result', $rules))->validate();

            $attempt->update([
                'status' => AttemptStatus::Succeeded,
                'result' => $result,
                'metadata' => $this->merged($attempt->metadata, $metadata),
                'finished_at' => now(),
                'lease_expires_at' => null,
            ]);

            $run->update([
                'status' => RunStatus::Succeeded,
                'result' => $result,
                'accepted_attempt_id' => $attempt->getKey(),
                'finished_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);

            RunSettled::dispatch($run);
            $this->releaseNext($run);

            return $run;
        });
    }

    /**
     * Record a failed attempt. The run is tried again when the error is
     * retryable and attempts remain; a run being cancelled settles as cancelled.
     */
    public function fail(RunAttempt $attempt, string $token, string $errorCode, ?string $message = null, ?bool $retryable = null, ?array $metadata = null): Run
    {
        return DB::transaction(function () use ($attempt, $token, $errorCode, $message, $retryable, $metadata) {
            $attempt = $this->locked($attempt);

            if (in_array($attempt->status, [AttemptStatus::Failed, AttemptStatus::Cancelled], true)) {
                $this->assertToken($attempt, $token);

                return $attempt->run;
            }

            $attempt = $this->leased($attempt, $token);
            $run = $attempt->run;
            $cancelling = $run->status === RunStatus::Cancelling;

            $attempt->update([
                'status' => $cancelling ? AttemptStatus::Cancelled : AttemptStatus::Failed,
                'error_code' => $errorCode,
                'error_message' => $message,
                'metadata' => $this->merged($attempt->metadata, $metadata),
                'finished_at' => now(),
                'lease_expires_at' => null,
            ]);

            $retry = $retryable ?? $this->types->get($run->type)->isRetryable($errorCode);

            return $this->afterAttempt($run, $errorCode, $message, $retry, RunStatus::Failed);
        });
    }

    /**
     * Park the run until something outside it happens, such as a person
     * answering. The attempt ends; a later attempt continues from the run's
     * state. Waiting does not use up retries.
     */
    public function wait(
        RunAttempt $attempt,
        string $token,
        string $reason,
        ?array $state = null,
        ?DateTimeInterface $resumeAt = null,
        ?array $metadata = null,
    ): Run {
        return DB::transaction(function () use ($attempt, $token, $reason, $state, $resumeAt, $metadata) {
            $attempt = $this->locked($attempt);

            if ($attempt->status === AttemptStatus::Suspended) {
                $this->assertToken($attempt, $token);

                return $attempt->run;
            }

            $attempt = $this->leased($attempt, $token);
            $run = RunModels::run()::query()->lockForUpdate()->findOrFail($attempt->run_id);

            if ($run->execution_mode === ExecutionMode::Reported) {
                throw new RunConflict('cannot_wait', 'A reported run cannot wait.');
            }

            if ($run->status === RunStatus::Cancelling) {
                $attempt->update(['status' => AttemptStatus::Cancelled, 'finished_at' => now(), 'lease_expires_at' => null]);

                return $this->afterAttempt($run, 'cancelled', null, false, RunStatus::Cancelled);
            }

            $attempt->update([
                'status' => AttemptStatus::Suspended,
                'metadata' => $this->merged($attempt->metadata, $metadata),
                'finished_at' => now(),
                'lease_expires_at' => null,
            ]);

            $run->update([
                'status' => RunStatus::Waiting,
                'waiting_on' => ['reason' => $reason],
                'resume_at' => $resumeAt,
                'state' => $state ?? $run->state,
            ]);
            RunWaiting::dispatch($run);

            return $run;
        });
    }

    /** Continue a waiting run. $data is merged into the run's state for the next attempt. */
    public function resume(Run $run, array $data = []): Run
    {
        return DB::transaction(function () use ($run, $data) {
            $run = RunModels::run()::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($run->status !== RunStatus::Waiting) {
                throw new RunConflict('not_waiting', 'The run is not waiting.');
            }

            $run->update([
                'status' => RunStatus::Queued,
                'state' => [...($run->state ?? []), ...$data],
                'waiting_on' => null,
                'resume_at' => null,
                'available_at' => now(),
            ]);
            RunResumed::dispatch($run);

            if ($run->execution_mode === ExecutionMode::Queue) {
                $this->dispatchQueued($run);
            }

            return $run;
        });
    }

    public function cancel(Run $run): Run
    {
        return DB::transaction(function () use ($run) {
            $run = RunModels::run()::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($run->status->isFinished()) {
                throw new RunConflict('run_finished', 'The run has already finished.');
            }

            if ($run->status === RunStatus::Cancelling) {
                return $run;
            }

            if ($run->status === RunStatus::Running && $run->execution_mode !== ExecutionMode::Reported) {
                $run->update(['status' => RunStatus::Cancelling, 'cancel_requested_at' => now()]);
                RunCancellationRequested::dispatch($run);

                return $run;
            }

            $run->attempts()->where('status', AttemptStatus::Running)->update([
                'status' => AttemptStatus::Cancelled,
                'finished_at' => now(),
                'lease_expires_at' => null,
            ]);
            $run->update([
                'status' => RunStatus::Cancelled,
                'cancel_requested_at' => now(),
                'finished_at' => now(),
                'waiting_on' => null,
                'resume_at' => null,
            ]);
            RunModels::dispatch()::query()->where('run_id', $run->getKey())->delete();
            RunSettled::dispatch($run);
            $this->releaseNext($run);

            return $run;
        });
    }

    /**
     * Expire attempts whose machine stopped renewing its lease, resume runs
     * whose wait is over, time out runs past their deadline, and resend queue
     * messages that never arrived. Schedule this every minute.
     *
     * @return array{expired_attempts: int, resumed_runs: int, timed_out_runs: int, dispatched_runs: int}
     */
    public function sweep(): array
    {
        $expired = 0;
        $stale = RunModels::attempt()::query()
            ->where('status', AttemptStatus::Running)
            ->where('lease_expires_at', '<', now())
            ->pluck('id');

        foreach ($stale as $id) {
            DB::transaction(function () use ($id, &$expired) {
                $taken = RunModels::attempt()::query()
                    ->whereKey($id)
                    ->where('status', AttemptStatus::Running)
                    ->where('lease_expires_at', '<', now())
                    ->update(['status' => AttemptStatus::Expired, 'finished_at' => now()]);

                if ($taken !== 1) {
                    return;
                }

                $expired++;
                $run = RunModels::attempt()::query()->findOrFail($id)->run;
                $retry = $this->types->has($run->type) && $this->types->get($run->type)->isRetryable('lease_expired');
                $this->afterAttempt($run, 'lease_expired', 'The runner stopped renewing its lease.', $retry, RunStatus::TimedOut);
            });
        }

        $resumed = 0;
        $due = RunModels::run()::query()
            ->where('status', RunStatus::Waiting)
            ->whereNotNull('resume_at')
            ->where('resume_at', '<=', now())
            ->beforeDeadline()
            ->get();

        foreach ($due as $run) {
            try {
                $this->resume($run);
                $resumed++;
            } catch (RunConflict) {
                // Resumed or cancelled by someone else in the meantime.
            }
        }

        $timedOut = 0;
        $overdue = RunModels::run()::query()
            ->whereIn('status', [RunStatus::Queued, RunStatus::Waiting])
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now())
            ->get();

        foreach ($overdue as $run) {
            DB::transaction(function () use ($run, &$timedOut) {
                $taken = RunModels::run()::query()
                    ->whereKey($run->getKey())
                    ->whereIn('status', [RunStatus::Queued, RunStatus::Waiting])
                    ->update([
                        'status' => RunStatus::TimedOut,
                        'error_code' => 'deadline_passed',
                        'finished_at' => now(),
                    ]);

                if ($taken === 1) {
                    RunModels::dispatch()::query()->where('run_id', $run->getKey())->delete();
                    $timedOut++;
                    $settled = $run->fresh();
                    RunSettled::dispatch($settled);
                    $this->releaseNext($settled);
                }
            });
        }

        $dispatched = 0;
        $pending = RunModels::dispatch()::query()->where('next_dispatch_at', '<=', now())
            ->orderBy('id')->limit(max(1, min(1000, (int) config('runs.queue.recovery_batch_size', 100))))->pluck('id');
        foreach ($pending as $id) {
            $dispatched += (int) $this->dispatchPending($id);
        }

        return ['expired_attempts' => $expired, 'resumed_runs' => $resumed, 'timed_out_runs' => $timedOut, 'dispatched_runs' => $dispatched];
    }

    /** Take a queued run for the Laravel queue. Null when another worker has it or it must wait its turn. */
    public function startQueued(Run $run): ?StartedAttempt
    {
        return DB::transaction(function () use ($run) {
            if ($this->isBlocked($run->fresh())) {
                return null;
            }

            $taken = RunModels::run()::query()
                ->whereKey($run->getKey())
                ->where('execution_mode', ExecutionMode::Queue)
                ->where('status', RunStatus::Queued)
                ->where('available_at', '<=', now())
                ->beforeDeadline()
                ->update(['status' => RunStatus::Running]);

            if ($taken !== 1) {
                return null;
            }
            $started = $this->startAttempt($run->fresh(), new Runner('queue'), $run->attempt_timeout_seconds);
            RunModels::dispatch()::query()->where('run_id', $run->getKey())->delete();

            return $started;
        });
    }

    private function startAttempt(Run $run, ?Runner $runner, int $leaseSeconds, ?array $metadata = null): StartedAttempt
    {
        $token = Str::random(48);
        $number = $run->attempt_count + 1;
        $deadline = $this->earliest(now()->addSeconds($run->attempt_timeout_seconds), $run->deadline_at);

        $run->update([
            'status' => RunStatus::Running,
            'attempt_count' => $number,
            'started_at' => $run->started_at ?? now(),
        ]);

        $attempt = RunModels::attempt()::create([
            'run_id' => $run->getKey(),
            'number' => $number,
            'status' => AttemptStatus::Running,
            'executor_type' => $runner?->type,
            'executor_id' => $runner?->id,
            'executor_name' => $runner?->name,
            'lease_token_hash' => hash('sha256', $token),
            'lease_expires_at' => $this->earliest(now()->addSeconds($leaseSeconds), $deadline),
            'deadline_at' => $deadline,
            'started_at' => now(),
            'metadata' => $metadata,
        ]);

        return new StartedAttempt($attempt, $token);
    }

    private function afterAttempt(Run $run, string $errorCode, ?string $message, bool $retry, RunStatus $terminal): Run
    {
        $run = RunModels::run()::query()->lockForUpdate()->findOrFail($run->getKey());
        $error = ['error_code' => $errorCode, 'error_message' => $message];

        if ($run->status === RunStatus::Cancelling) {
            $run->update([...$error, 'status' => RunStatus::Cancelled, 'finished_at' => now()]);
            RunModels::dispatch()::query()->where('run_id', $run->getKey())->delete();
            RunSettled::dispatch($run);
            $this->releaseNext($run);

            return $run;
        }

        $run->failure_count++;

        if ($retry && $run->canRetry()) {
            $backoff = array_values((array) config('runs.defaults.retry_backoff_seconds', [0]));
            $delay = (int) ($backoff[min($run->failure_count - 1, count($backoff) - 1)] ?? 0);

            $run->fill([...$error, 'status' => RunStatus::Queued, 'available_at' => now()->addSeconds($delay)])->save();
            RunRetryScheduled::dispatch($run);

            if ($run->execution_mode === ExecutionMode::Queue) {
                $this->dispatchQueued($run);
            }

            return $run;
        }

        $run->fill([...$error, 'status' => $terminal, 'finished_at' => now()])->save();
        RunModels::dispatch()::query()->where('run_id', $run->getKey())->delete();
        RunSettled::dispatch($run);
        $this->releaseNext($run);

        return $run;
    }

    private function applyPolicy(ConcurrencyPolicy $policy, array $attributes): void
    {
        if (! in_array($policy, [ConcurrencyPolicy::Reject, ConcurrencyPolicy::Supersede], true)) {
            return;
        }

        $active = RunModels::run()::query()
            ->where('owner_type', $attributes['owner_type'])
            ->where('owner_id', $attributes['owner_id'])
            ->where('concurrency_key', $attributes['concurrency_key'])
            ->whereIn('status', RunStatus::unfinished())
            ->lockForUpdate()
            ->get();

        if ($active->isEmpty()) {
            return;
        }

        if ($policy === ConcurrencyPolicy::Reject) {
            throw new RunConflict('concurrency_busy', 'Another run with this concurrency key has not finished.');
        }

        foreach ($active as $run) {
            if ($run->status !== RunStatus::Cancelling) {
                $this->cancel($run);
            }
        }
    }

    private function isBlocked(Run $run): bool
    {
        return $run->concurrency_policy === ConcurrencyPolicy::Queue
            && $run->concurrency_key !== null
            && ! RunModels::run()::query()->whereKey($run->getKey())->notBlocked()->exists();
    }

    /** Hand the queue the next run that was waiting its turn behind $run. */
    private function releaseNext(Run $run): void
    {
        if ($run->concurrency_key === null) {
            return;
        }

        $next = RunModels::run()::query()
            ->where('owner_type', $run->owner_type)
            ->where('owner_id', $run->owner_id)
            ->where('concurrency_key', $run->concurrency_key)
            ->where('concurrency_policy', ConcurrencyPolicy::Queue)
            ->where('execution_mode', ExecutionMode::Queue)
            ->where('status', RunStatus::Queued)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($next) {
            RunModels::dispatch()::query()->updateOrCreate(['run_id' => $next->getKey()], ['next_dispatch_at' => now()]);
            $this->dispatchQueued($next);
        }
    }

    private function dispatchQueued(Run $run): void
    {
        $dispatch = RunModels::dispatch()::query()->firstOrCreate(['run_id' => $run->getKey()], ['next_dispatch_at' => now()]);
        DB::afterCommit(fn () => $this->dispatchPending($dispatch->id));
    }

    private function ensureQueueDelivery(Run $run): void
    {
        if ($run->execution_mode === ExecutionMode::Queue && $run->status === RunStatus::Queued) {
            $this->dispatchQueued($run);
        }
    }

    private function dispatchPending(int $id): bool
    {
        $dispatch = RunModels::dispatch()::query()->find($id);
        if (! $dispatch) {
            return false;
        }
        $run = $dispatch->run;
        if (! $run || $run->execution_mode !== ExecutionMode::Queue || $run->status !== RunStatus::Queued) {
            $dispatch->delete();

            return false;
        }
        if ($run->deadline_at !== null && ! $run->deadline_at->isFuture()) {
            return false;
        }
        $token = (string) Str::uuid();
        $next = $run->available_at?->isFuture() ? $run->available_at->copy() : now();
        $taken = RunModels::dispatch()::query()->whereKey($id)->where('next_dispatch_at', '<=', now())->update([
            'dispatch_token' => $token,
            'next_dispatch_at' => $next->addSeconds(max(1, (int) config('runs.queue.redispatch_after_seconds', 300))),
            'dispatch_attempts' => DB::raw('dispatch_attempts + 1'),
        ]);
        if ($taken !== 1) {
            return false;
        }
        try {
            ExecuteRun::dispatch($run->getKey())->onConnection(config('runs.queue.connection'))
                ->onQueue(config('runs.queue.name'))->delay($run->available_at);
        } catch (Throwable $exception) {
            RunModels::dispatch()::query()->whereKey($id)->where('dispatch_token', $token)->update([
                'last_error' => $exception::class,
                'next_dispatch_at' => now()->addSeconds(max(1, (int) config('runs.queue.dispatch_retry_seconds', 30))),
            ]);
            report($exception);

            return false;
        }

        return true;
    }

    private function artifactAt(RunAttempt $attempt, string $path): ?RunArtifact
    {
        return RunModels::artifact()::query()
            ->where('attempt_id', $attempt->getKey())
            ->where('path_hash', hash('sha256', $path))
            ->first();
    }

    /** The attempt a declared file belongs to, while it may still receive files. */
    private function receiving(RunArtifact $artifact): RunAttempt
    {
        $attempt = $artifact->attempt;
        if ($attempt->status !== AttemptStatus::Running || ! $attempt->lease_expires_at?->isFuture()) {
            throw new RunConflict('lease_lost', 'This attempt no longer holds the run.');
        }

        return $attempt;
    }

    /**
     * Store bytes under a key that includes their checksum, so a racing write
     * of different bytes never overwrites a file an artifact row vouches for.
     *
     * @return array{0: string, 1: string}
     */
    private function put(RunAttempt $attempt, string $pathHash, string $sha256, UploadedFile|string $contents): array
    {
        $disk = (string) config('runs.artifacts.disk', 'local');
        $directory = trim((string) config('runs.artifacts.prefix', 'runs'), '/')
            .'/'.$attempt->run->public_id.'/'.$attempt->public_id;
        $name = $pathHash.'-'.$sha256;

        if ($contents instanceof UploadedFile) {
            return [$disk, Storage::disk($disk)->putFileAs($directory, $contents, $name)];
        }

        Storage::disk($disk)->put("{$directory}/{$name}", $contents);

        return [$disk, "{$directory}/{$name}"];
    }

    private function markReceived(RunArtifact $artifact, string $disk, string $storageKey): RunArtifact
    {
        $taken = RunModels::artifact()::query()
            ->whereKey($artifact->getKey())
            ->whereNull('received_at')
            ->update(['disk' => $disk, 'storage_key' => $storageKey, 'received_at' => now()]);

        $artifact = $artifact->fresh();
        if ($taken === 1) {
            ArtifactReceived::dispatch($artifact);
        }

        return $artifact;
    }

    private function assertFingerprint(Run $run, ?string $fingerprint): void
    {
        if (($run->request_fingerprint !== null || $fingerprint !== null)
            && ($run->request_fingerprint === null || $fingerprint === null || ! hash_equals($run->request_fingerprint, $fingerprint))) {
            throw new RunConflict('idempotency_conflict', 'This idempotency key was used for a different request.');
        }
    }

    private function locked(RunAttempt $attempt): RunAttempt
    {
        return RunModels::attempt()::query()->lockForUpdate()->findOrFail($attempt->getKey());
    }

    /** The attempt, after proving the caller holds its current, unexpired lease. */
    private function leased(RunAttempt $attempt, string $token): RunAttempt
    {
        $attempt = $attempt->fresh();
        $this->assertToken($attempt, $token);

        if (! $attempt->holdsLease($token)) {
            throw new RunConflict('lease_lost', 'This attempt no longer holds the run.');
        }

        return $attempt;
    }

    private function assertToken(RunAttempt $attempt, string $token): void
    {
        if ($attempt->lease_token_hash === null || ! hash_equals($attempt->lease_token_hash, hash('sha256', $token))) {
            throw new InvalidLeaseToken;
        }
    }

    private function findByIdempotencyKey(array $attributes): ?Run
    {
        if (empty($attributes['idempotency_key'])) {
            return null;
        }

        return RunModels::run()::query()
            ->where('owner_type', $attributes['owner_type'])
            ->where('owner_id', $attributes['owner_id'])
            ->where('idempotency_key', $attributes['idempotency_key'])
            ->first();
    }

    private function merged(?array $current, ?array $added): ?array
    {
        return $added === null ? $current : [...($current ?? []), ...$added];
    }

    private function prefix(string $key, array $rules): array
    {
        $prefixed = [$key => ['array']];
        foreach ($rules as $field => $rule) {
            $prefixed["{$key}.{$field}"] = $rule;
        }

        return $prefixed;
    }

    private function earliest(Carbon $time, ?Carbon $limit): Carbon
    {
        return $limit !== null && $limit->lt($time) ? $limit->copy() : $time;
    }
}
