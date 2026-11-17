<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('runs.runs_table', 'work_runs'), function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->morphs('owner');

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
            $table->json('result')->nullable();
            $table->json('progress')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 200)->nullable();

            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(1);
            $table->unsignedInteger('lease_seconds');
            $table->unsignedInteger('attempt_timeout_seconds');
            $table->timestamp('available_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->unsignedBigInteger('accepted_attempt_id')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['owner_type', 'owner_id', 'idempotency_key']);
            $table->index(['owner_type', 'owner_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['trigger_type', 'trigger_id']);
            $table->index(['execution_mode', 'status', 'available_at']);
            $table->index(['status', 'deadline_at']);
        });

        Schema::create(config('runs.attempts_table', 'work_run_attempts'), function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('run_id')->constrained(config('runs.runs_table', 'work_runs'))->cascadeOnDelete();
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
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'number']);
            $table->index(['status', 'lease_expires_at']);
        });

        Schema::create(config('runs.artifacts_table', 'work_run_artifacts'), function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('run_id')->constrained(config('runs.runs_table', 'work_runs'))->cascadeOnDelete();
            $table->foreignId('attempt_id')->constrained(config('runs.attempts_table', 'work_run_attempts'))->cascadeOnDelete();
            $table->string('path', 1024);
            // Long paths cannot be indexed on every database, so uniqueness is kept on a hash.
            $table->char('path_hash', 64);
            $table->string('type')->nullable();
            $table->string('media_type');
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('disk');
            $table->string('storage_key');
            $table->timestamp('received_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'path_hash']);
            $table->index(['run_id', 'id']);
        });

        Schema::create(config('runs.log_chunks_table', 'work_run_log_chunks'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained(config('runs.runs_table', 'work_runs'))->cascadeOnDelete();
            $table->foreignId('attempt_id')->constrained(config('runs.attempts_table', 'work_run_attempts'))->cascadeOnDelete();
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
        Schema::dropIfExists(config('runs.runs_table', 'work_runs'));
    }
};
