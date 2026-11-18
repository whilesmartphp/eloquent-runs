# eloquent-runs

Record work done anywhere, and its result, in a Laravel application.

A **run** is one unit of work: a screenshot to take, a pull request to review, a backup to make. It belongs to an owner (a workspace, a team), may point at what it works on (its subject) and at what started it (its trigger), and ends with a result.

A run is executed in one of three ways:

| Mode | Who does the work | Example |
|---|---|---|
| `reported` | Someone outside the app, who uploads the result | CI runs a test suite, then uploads its report |
| `pull` | A machine that claims the run over HTTP | A browser worker, a build runner on a customer's computer |
| `queue` | A Laravel queued job that calls the run type's handler | A PHP task such as sending a digest |

Each try at a run is an **attempt**. An attempt holds a lease: a token and an expiry. Every call that changes the attempt must present the token, and a machine has to send heartbeats to keep the lease. When a lease runs out, the attempt expires, and the run is either retried as a new attempt or settled. A machine that lost its lease cannot settle the run afterwards.

## Install

```bash
composer require whilesmart/eloquent-runs
php artisan migrate
```

For UUID keys instead of integers, set `RUNS_UUIDS=true` before the first migration. It changes the type of every id, owner and reference column, so it cannot be switched later.

Schedule the sweep, which expires abandoned attempts, resumes runs whose wait is over, times out overdue runs and recovers queued work:

```php
Schedule::command('runs:sweep')->everyMinute();
```

## Run types

Every run has a registered `type`. A type validates the run's input and result, and decides which errors are worth retrying.

```php
use Whilesmart\Runs\Contracts\RunType;

class ScreenshotRun implements RunType
{
    public function inputRules(): array
    {
        return ['url' => ['required', 'url']];
    }

    public function resultRules(): array
    {
        return ['images' => ['required', 'integer', 'min:1']];
    }

    public function isRetryable(?string $errorCode): bool
    {
        return in_array($errorCode, ['lease_expired', 'browser_crashed'], true);
    }
}
```

Register it in `config/runs.php`:

```php
'types' => ['screenshot' => ScreenshotRun::class],
```

A type that runs on the queue also implements `HandlesRun`. Its `handle()` returns the result, or throws `RunFailed` with an error code.

If a handler returns a result that fails its type's validation, the attempt fails with `invalid_result`. The type's `isRetryable()` policy decides whether another attempt is scheduled.

## Queue delivery

Queue delivery is recorded in the same database transaction as the run or retry. The message is sent after commit. A failed push leaves the run queued, and `runs:sweep` tries delivery again.

Configure recovery in `runs.queue`:

| Setting | Default | Purpose |
|---|---|---|
| `dispatch_retry_seconds` | 30 | Wait after a failed queue push |
| `redispatch_after_seconds` | 300 | Wait before replacing a message whose run has not started |
| `recovery_batch_size` | 100 | Maximum deliveries recovered per sweep, capped at 1,000 |

The recovery window starts after any retry delay. Duplicate messages cannot skip that delay or start a second active attempt. Queue delivery can happen more than once; handlers that call external systems must still make those operations safe to repeat after a lease expires.

The sweep reports expired attempts, timed out runs and dispatched runs. Keep both the scheduler and queue workers running.

## Request fingerprints

By default, a repeated idempotency key returns the owner's original run. Hosts can pass a server-generated `request_fingerprint` to `RunService::create()` to require matching request content. A different or missing fingerprint then returns `409` with `idempotency_conflict`. Keys created without a fingerprint retain the original behavior when replayed without one.

For HTTP creation, replace `requests.store_run` with a request that computes and validates the fingerprint from trusted, normalized input. The default request does not accept a client-supplied fingerprint. Include every field that changes the meaning of the work, and keep the normalization stable across deployments.

## Concurrency

Runs that share a `concurrency_key` for the same owner follow the `concurrency_policy` given when each is created:

| Policy | A new run with the key |
|---|---|
| `parallel` (default) | Runs alongside the others |
| `queue` | Waits until every earlier unfinished run with the key has finished, then starts in order |
| `reject` | Is refused with `409 concurrency_busy` while another run with the key is unfinished |
| `supersede` | Cancels the unfinished runs with the key, then starts |

A deploy per app might use `queue`; a review run per pull request might use `supersede`, so a new push replaces the review of the old one. Reported runs start at once, so they cannot use `queue`.

## Waiting

A run can pause until something outside it happens, such as a person answering a question. The attempt holding it calls `wait` with a reason, optional `state` and an optional `resume_at`; the attempt ends and the run becomes `waiting`, holding no lease. `POST /runs/{run}/resume` merges its `data` into the run's `state` and queues the run, and the next attempt carries on from that state. The sweep resumes runs whose `resume_at` has passed. Waiting does not use up retries.

A queue handler waits by throwing `RunWaits`.

## Declared files

An attempt can declare its files before uploading them, with each file's path, media type, size and SHA-256. Completion then fails with `409 artifacts_missing`, listing the paths, until every declared file has arrived, and an upload must match its declaration. Readers only ever see files that have arrived.

Hosts that receive uploads through their own routes pass the bytes to `RunService::receiveArtifact()`, which checks them against the declaration. Hosts that let clients upload straight to object storage call `RunService::markArtifactReceived()` with the disk and key.

## Authorization

People reach runs through [`eloquent-owner-access`](https://github.com/whilesmartphp/eloquent-owner-access): bind an `OwnerAuthorizer` that decides who may use which owner's runs.

Machines claim runs through a `RunnerAuthorizer`, which identifies the machine from the request and limits which runs it may take. The default refuses every machine, and the claim route stays off until you enable it:

```php
'register_runner_routes' => true,
'runner_authorizer' => App\Runs\MachineTokenAuthorizer::class,
```

## API

User routes use `route_middleware` (default `api`, `auth:sanctum`) under `route_prefix` (default `api`):

| Method | Path | Does |
|---|---|---|
| GET | `/runs` | List runs; filter by `type`, `status`, `execution_mode`, `trigger_name`, and `owner_*`, `subject_*` or `trigger_*` pairs |
| POST | `/runs` | Create a run. A repeated `idempotency_key` for the same owner returns the first run. A `reported` run also returns its upload attempt and token |
| GET | `/runs/{run}` | Show a run |
| POST | `/runs/{run}/cancel` | Cancel a queued or waiting run at once, or ask a running machine to stop |
| POST | `/runs/{run}/resume` | Continue a waiting run, merging `data` into its state |
| GET | `/runs/{run}/attempts` | List attempts |
| GET | `/runs/{run}/artifacts` | Files of the accepted attempt, so none before the run succeeds; `?all=1` for every attempt |
| GET | `/runs/{run}/artifacts/{artifact}` | Download a file |
| GET | `/runs/{run}/logs` | Log lines after the `?after` cursor |

Runner routes use `runner_route_middleware` (default `api`) under `runner_route_prefix` (default `api/runner`). Attempt calls send the lease token in the `X-Lease-Token` header.

| Method | Path | Does |
|---|---|---|
| POST | `/claim` | Take up to `limit` (default 1, at most 50) of the oldest available runs as `claims`; `204` when there are none. `metadata` is recorded on each attempt |
| POST | `/attempts/{attempt}/heartbeat` | Extend the lease and report progress; answers whether cancellation was requested |
| POST | `/attempts/{attempt}/logs` | Append numbered log lines; a resent number is skipped |
| POST | `/attempts/{attempt}/manifest` | Declare the files the attempt will upload |
| POST | `/attempts/{attempt}/artifacts` | Upload a file with its path and optional checksum |
| POST | `/attempts/{attempt}/complete` | Settle the run with a result |
| POST | `/attempts/{attempt}/fail` | Fail the attempt; the run retries if its type allows the error code, attempts remain and its deadline has not passed |
| POST | `/attempts/{attempt}/wait` | Park the run with a `reason`, optional `state` and optional `resume_at` |

Heartbeat, complete, fail and wait accept `metadata`, merged into the attempt's metadata: which machine or version ran it, what it cost.

A request that no longer matches the run's state, such as completing after the lease expired, gets `409` with a `code`, and `details` when there is more to say.

## Events

Dispatched after the database commit: `RunCreated`, `RunClaimed`, `RunRetryScheduled`, `RunCancellationRequested`, `RunWaiting`, `RunResumed`, `RunSettled`, `ArtifactReceived`.

## Customization

In `config/runs.php`, without copying controllers or routes:

- `models`: subclass the run, attempt, artifact, log or dispatch models.
- `requests.store_run`: replace the request that validates and authorizes run creation.
- `resources`: replace how runs, attempts and artifacts are serialized.
- `controllers`: replace a controller as a last resort.
- `*_table`: rename the tables. The defaults start with `work_` so they never meet Laravel's own `jobs` tables.

Bind `Whilesmart\Runs\Contracts\ResponseFormatter` to change the response envelope.

## Testing

```bash
composer test
composer pint:test
```

The MySQL suite requires a dedicated database whose name ends in `_tests`. Concurrent HTTP tests also require PHP's `pcntl` extension. CI runs the suite against SQLite and MySQL; the database queue tests execute real queue workers.
