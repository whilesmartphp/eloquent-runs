<?php

namespace Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\OwnerAccess\Contracts\OwnerAuthorizer;
use Whilesmart\Runs\Models\Run;

class RunAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OwnerAuthorizer::class, new class implements OwnerAuthorizer
        {
            public function authorize(?Authenticatable $user, string $ownerType, mixed $ownerId): bool
            {
                return false;
            }

            public function scope(Builder $query, ?Authenticatable $user, string $ownerTypeColumn = 'owner_type', string $ownerIdColumn = 'owner_id'): Builder
            {
                return $query->whereRaw('0 = 1');
            }
        });
    }

    #[Test]
    public function store_is_forbidden_when_the_authorizer_denies(): void
    {
        $this->createRun()->assertForbidden();

        $this->assertDatabaseCount('work_runs', 0);
    }

    #[Test]
    public function reading_and_cancelling_are_forbidden_when_the_authorizer_denies(): void
    {
        $run = Run::factory()->create(['type' => 'screenshot']);

        foreach (['', '/attempts', '/artifacts', '/logs'] as $suffix) {
            $this->getJson("/api/runs/{$run->public_id}{$suffix}")->assertForbidden();
        }
        $this->postJson("/api/runs/{$run->public_id}/cancel")->assertForbidden();

        $this->assertSame('queued', $run->fresh()->status->value);
    }

    #[Test]
    public function index_returns_nothing_when_the_scope_denies(): void
    {
        Run::factory()->create();

        $this->getJson('/api/runs')->assertOk()->assertJsonPath('data.meta.total', 0);
    }
}
