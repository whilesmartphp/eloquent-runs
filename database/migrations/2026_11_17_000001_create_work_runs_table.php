<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private bool $uuids;

    public function up(): void
    {
        $this->uuids = (bool) config('runs.uuids', false);
        $runs = config('runs.runs_table', 'work_runs');
        $attempts = config('runs.attempts_table', 'work_run_attempts');

        Schema::create($runs, function (Blueprint $table) {
            $this->key($table);
            $table->ulid('public_id')->unique();
            $this->uuids ? $table->uuidMorphs('owner') : $table->morphs('owner');

            // String ids so a subject, trigger or requester can be any model,
            // whether its key is an integer, a UUID or a ULID.
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('trigger_type')->nullable();
            $table->string('trigger_id')->nullable();
            $table->string('trigger_name')->nullable();
            $table->string('requested_by_type')->nullable();
            $table->string('requested_by_id')->nullable();

            $table->string('type');
            $table->string('execution_mode');
            $table->string('status');
            $table->json('input')->nullable();
            $table->json('state')->nullable();
            $table->json('result')->nullable();
            $table->json('progress')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 200)->nullable();
            $table->string('request_fingerprint')->nullable();
            $table->string('concurrency_key', 200)->nullable();
            $table->string('concurrency_policy')->default('parallel');

            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(1);
            $table->unsignedInteger('lease_seconds');
            $table->unsignedInteger('attempt_timeout_seconds');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $this->uuids
                ? $table->uuid('accepted_attempt_id')->nullable()
                : $table->unsignedBigInteger('accepted_attempt_id')->nullable();

            $table->json('waiting_on')->nullable();
            $table->timestamp('resume_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['owner_type', 'owner_id', 'idempotency_key']);
            $table->index(['owner_type', 'owner_id', 'created_at']);
            $table->index(['owner_type', 'owner_id', 'concurrency_key', 'status']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['trigger_type', 'trigger_id']);
            $table->index(['execution_mode', 'status', 'available_at']);
            $table->index(['status', 'deadline_at']);
            $table->index(['status', 'resume_at']);
        });

        Schema::create(config('runs.dispatches_table', 'work_run_dispatches'), function (Blueprint $table) use ($runs) {
            $table->id();
            $this->reference($table, 'run_id', $runs)->unique();
            $table->timestamp('next_dispatch_at')->index();
            $table->uuid('dispatch_token')->nullable();
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create($attempts, function (Blueprint $table) use ($runs) {
            $this->key($table);
            $table->ulid('public_id')->unique();
            $this->reference($table, 'run_id', $runs);
            $table->unsignedInteger('number');
            $table->string('status');
            $table->string('executor_type')->nullable();
            $table->string('executor_id')->nullable();
            $table->string('executor_name')->nullable();
            $table->string('lease_token_hash', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('result')->nullable();
            $table->json('metadata')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'number']);
            $table->index(['status', 'lease_expires_at']);
        });

        Schema::create(config('runs.artifacts_table', 'work_run_artifacts'), function (Blueprint $table) use ($runs, $attempts) {
            $this->key($table);
            $table->ulid('public_id')->unique();
            $this->reference($table, 'run_id', $runs);
            $this->reference($table, 'attempt_id', $attempts);
            $table->string('path', 1024);
            // Long paths cannot be indexed on every database, so uniqueness is kept on a hash.
            $table->char('path_hash', 64);
            $table->string('type')->nullable();
            $table->string('media_type');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            // Empty until the bytes arrive: a declared file has a row before its upload.
            $table->string('disk')->nullable();
            $table->string('storage_key')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'path_hash']);
            $table->index(['run_id', 'received_at']);
        });

        Schema::create(config('runs.log_chunks_table', 'work_run_log_chunks'), function (Blueprint $table) use ($runs, $attempts) {
            // Always an increasing integer: readers page through logs by this id.
            $table->id();
            $this->reference($table, 'run_id', $runs);
            $this->reference($table, 'attempt_id', $attempts);
            $table->unsignedInteger('sequence');
            $table->string('stream')->default('stdout');
            $table->text('content');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['attempt_id', 'sequence']);
            $table->index(['run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('runs.log_chunks_table', 'work_run_log_chunks'));
        Schema::dropIfExists(config('runs.artifacts_table', 'work_run_artifacts'));
        Schema::dropIfExists(config('runs.attempts_table', 'work_run_attempts'));
        Schema::dropIfExists(config('runs.dispatches_table', 'work_run_dispatches'));
        Schema::dropIfExists(config('runs.runs_table', 'work_runs'));
    }

    private function key(Blueprint $table): void
    {
        $this->uuids ? $table->uuid('id')->primary() : $table->id();
    }

    private function reference(Blueprint $table, string $column, string $on)
    {
        return ($this->uuids ? $table->foreignUuid($column) : $table->foreignId($column))
            ->constrained($on)
            ->cascadeOnDelete();
    }
};
