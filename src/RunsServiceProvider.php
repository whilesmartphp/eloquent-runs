<?php

namespace Whilesmart\Runs;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Whilesmart\Runs\Authorizers\DenyAllRunnerAuthorizer;
use Whilesmart\Runs\Console\SweepRunsCommand;
use Whilesmart\Runs\Contracts\ResponseFormatter;
use Whilesmart\Runs\Contracts\RunnerAuthorizer;
use Whilesmart\Runs\Services\RunService;
use Whilesmart\Runs\Support\JsonResponseFormatter;
use Whilesmart\Runs\Support\RunTypeRegistry;

class RunsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/runs.php', 'runs');

        $this->app->singleton(RunTypeRegistry::class);
        $this->app->singleton(RunService::class);
        $this->app->bindIf(ResponseFormatter::class, JsonResponseFormatter::class);
        $this->app->bindIf(RunnerAuthorizer::class, function ($app) {
            $class = config('runs.runner_authorizer', DenyAllRunnerAuthorizer::class);
            $authorizer = $app->make($class);

            if (! $authorizer instanceof RunnerAuthorizer) {
                throw new InvalidArgumentException("[runs.runner_authorizer] {$class} must implement ".RunnerAuthorizer::class.'.');
            }

            return $authorizer;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/runs.php' => config_path('runs.php'),
        ], 'runs-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'runs-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([SweepRunsCommand::class]);
        }

        if (config('runs.register_routes', true)) {
            Route::middleware(config('runs.route_middleware', ['api', 'auth:sanctum']))
                ->prefix(config('runs.route_prefix', 'api'))
                ->group(__DIR__.'/../routes/api.php');

            Route::middleware(config('runs.runner_route_middleware', ['api']))
                ->prefix(config('runs.runner_route_prefix', 'api/runner'))
                ->group(__DIR__.'/../routes/runner.php');
        }
    }
}
