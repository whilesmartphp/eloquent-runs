<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Tests\Fixtures\AddNumbersType;
use Tests\Fixtures\ScreenshotType;
use Tests\Fixtures\TokenRunnerAuthorizer;
use Whilesmart\OwnerAccess\OwnerAccessServiceProvider;
use Whilesmart\Runs\RunsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected const OWNER = 'App\\Models\\Workspace';

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            OwnerAccessServiceProvider::class,
            RunsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('runs.route_middleware', ['api']);
        $app['config']->set('runs.register_runner_routes', true);
        $app['config']->set('runs.runner_authorizer', TokenRunnerAuthorizer::class);
        $app['config']->set('runs.defaults.retry_backoff_seconds', [0]);
        $app['config']->set('runs.types', [
            'screenshot' => ScreenshotType::class,
            'add' => AddNumbersType::class,
        ]);
    }

    protected function createRun(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/runs', [
            'owner_type' => self::OWNER,
            'owner_id' => 1,
            'type' => 'screenshot',
            'input' => ['url' => 'https://example.com'],
            ...$overrides,
        ]);
    }

    protected function claim(string $runner = 'runner-1', array $body = []): TestResponse
    {
        return $this->withToken($runner)->postJson('/api/runner/claim', $body);
    }

    protected function asAttempt(string $token): static
    {
        return $this->withHeader('X-Lease-Token', $token);
    }
}
