<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Tests\Fixtures\AddNumbersType;
use Tests\Fixtures\AskType;
use Tests\Fixtures\Owners;
use Tests\Fixtures\QueueTestServiceProvider;
use Tests\Fixtures\ScreenshotType;
use Tests\Fixtures\TokenRunnerAuthorizer;
use Whilesmart\OwnerAccess\OwnerAccessServiceProvider;
use Whilesmart\Runs\RunsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    use DatabaseMigrations;

    protected const OWNER = 'App\\Models\\Workspace';

    protected function getPackageProviders($app): array
    {
        return [
            OwnerAccessServiceProvider::class,
            RunsServiceProvider::class,
            QueueTestServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $server = in_array(env('DB_CONNECTION'), ['mysql', 'pgsql'], true) ? env('DB_CONNECTION') : null;
        if ($server && ! str_ends_with((string) env('DB_DATABASE'), '_tests')) {
            throw new \RuntimeException('Database server tests require a dedicated database ending in _tests.');
        }
        if ($server) {
            $app['config']->set('database.default', $server);
        }
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('runs.route_middleware', ['api']);
        $app['config']->set('runs.register_runner_routes', true);
        $app['config']->set('runs.runner_authorizer', TokenRunnerAuthorizer::class);
        $app['config']->set('runs.defaults.retry_backoff_seconds', [0]);
        $app['config']->set('runs.types', [
            'screenshot' => ScreenshotType::class,
            'add' => AddNumbersType::class,
            'ask' => AskType::class,
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
            'owner_id' => Owners::key($overrides['owner_id'] ?? 1),
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
