<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('runs.runs_table', 'work_runs'), function (Blueprint $table) {
            $table->string('request_fingerprint')->nullable();
        });

        Schema::create(config('runs.dispatches_table', 'work_run_dispatches'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->unique()->constrained(config('runs.runs_table', 'work_runs'))->cascadeOnDelete();
            $table->timestamp('next_dispatch_at')->index();
            $table->uuid('dispatch_token')->nullable();
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->timestamps();
        });

        DB::table(config('runs.dispatches_table', 'work_run_dispatches'))->insertUsing(
            ['run_id', 'next_dispatch_at', 'created_at', 'updated_at'],
            DB::table(config('runs.runs_table', 'work_runs'))->select('id')
                ->selectRaw('?, ?, ?', [now(), now(), now()])
                ->where('execution_mode', 'queue')->where('status', 'queued')->whereNull('deleted_at'),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(config('runs.dispatches_table', 'work_run_dispatches'));
        Schema::table(config('runs.runs_table', 'work_runs'), function (Blueprint $table) {
            $table->dropColumn('request_fingerprint');
        });
    }
};
