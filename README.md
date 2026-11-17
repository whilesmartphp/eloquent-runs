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

Schedule the sweep, which expires abandoned attempts and times out overdue runs:

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
| POST | `/runs/{run}/cancel` | Cancel a queued run at once, or ask a running machine to stop |
| GET | `/runs/{run}/attempts` | List attempts |
| GET | `/runs/{run}/artifacts` | Files of the accepted attempt, so none before the run succeeds; `?all=1` for every attempt |
| GET | `/runs/{run}/artifacts/{artifact}` | Download a file |
| GET | `/runs/{run}/logs` | Log lines after the `?after` cursor |

Runner routes use `runner_route_middleware` (default `api`) under `runner_route_prefix` (default `api/runner`). Attempt calls send the lease token in the `X-Lease-Token` header.

| Method | Path | Does |
|---|---|---|
| POST | `/claim` | Take the oldest available run; `204` when there is none |
| POST | `/attempts/{attempt}/heartbeat` | Extend the lease and report progress; answers whether cancellation was requested |
| POST | `/attempts/{attempt}/logs` | Append numbered log lines; a resent number is skipped |
| POST | `/attempts/{attempt}/artifacts` | Upload a file with its path and optional checksum |
| POST | `/attempts/{attempt}/complete` | Settle the run with a result |
| POST | `/attempts/{attempt}/fail` | Fail the attempt; the run retries if its type allows the error code, attempts remain and its deadline has not passed |

A request that no longer matches the run's state, such as completing after the lease expired, gets `409` with a `code`.

## Events

Dispatched after the database commit: `RunCreated`, `RunClaimed`, `RunRetryScheduled`, `RunCancellationRequested`, `RunSettled`, `ArtifactReceived`.

## Customization

In `config/runs.php`, without copying controllers or routes:

- `models`: subclass the run, attempt, artifact or log models.
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
