<?php

use Whilesmart\Runs\Authorizers\DenyAllRunnerAuthorizer;
use Whilesmart\Runs\Http\Controllers\AttemptController;
use Whilesmart\Runs\Http\Controllers\RunController;
use Whilesmart\Runs\Http\Controllers\RunnerController;
use Whilesmart\Runs\Http\Requests\StoreRunRequest;
use Whilesmart\Runs\Http\Resources\ArtifactResource;
use Whilesmart\Runs\Http\Resources\AttemptResource;
use Whilesmart\Runs\Http\Resources\RunResource;
use Whilesmart\Runs\Models\Run;
use Whilesmart\Runs\Models\RunArtifact;
use Whilesmart\Runs\Models\RunAttempt;
use Whilesmart\Runs\Models\RunLogChunk;

return [
    'register_routes' => env('RUNS_REGISTER_ROUTES', true),
    'route_prefix' => env('RUNS_ROUTE_PREFIX', 'api'),
    'route_middleware' => ['api', 'auth:sanctum'],

    // Routes machines use: claiming work, and everything an attempt does with
    // its lease token. Off until the host binds a RunnerAuthorizer.
    'register_runner_routes' => env('RUNS_REGISTER_RUNNER_ROUTES', false),
    'runner_route_prefix' => env('RUNS_RUNNER_ROUTE_PREFIX', 'api/runner'),
    'runner_route_middleware' => ['api'],
    'runner_authorizer' => DenyAllRunnerAuthorizer::class,

    'runs_table' => env('RUNS_TABLE', 'work_runs'),
    'attempts_table' => env('RUNS_ATTEMPTS_TABLE', 'work_run_attempts'),
    'artifacts_table' => env('RUNS_ARTIFACTS_TABLE', 'work_run_artifacts'),
    'log_chunks_table' => env('RUNS_LOG_CHUNKS_TABLE', 'work_run_log_chunks'),

    // Registered run types: name => class implementing Contracts\RunType.
    'types' => [],

    'defaults' => [
        'max_attempts' => 1,
        'lease_seconds' => 90,
        'attempt_timeout_seconds' => 3600,
        'retry_backoff_seconds' => [10, 60, 300],
    ],

    'artifacts' => [
        'disk' => env('RUNS_ARTIFACTS_DISK', 'local'),
        'prefix' => 'runs',
        'max_bytes' => 50 * 1024 * 1024,
    ],

    'queue' => [
        'connection' => env('RUNS_QUEUE_CONNECTION'),
        'name' => env('RUNS_QUEUE'),
    ],

    'models' => [
        'run' => Run::class,
        'attempt' => RunAttempt::class,
        'artifact' => RunArtifact::class,
        'log_chunk' => RunLogChunk::class,
    ],

    'resources' => [
        'run' => RunResource::class,
        'attempt' => AttemptResource::class,
        'artifact' => ArtifactResource::class,
    ],

    'requests' => [
        'store_run' => StoreRunRequest::class,
    ],

    'controllers' => [
        'runs' => RunController::class,
        'runner' => RunnerController::class,
        'attempts' => AttemptController::class,
    ],
];
