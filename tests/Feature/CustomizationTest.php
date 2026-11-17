<?php

namespace Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\RouteCollection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Whilesmart\Runs\Contracts\ResponseFormatter;
use Whilesmart\Runs\Http\Requests\StoreRunRequest;
use Whilesmart\Runs\RunsServiceProvider;

class CustomizationTest extends TestCase
{
    #[Test]
    public function a_host_can_swap_the_response_envelope(): void
    {
        $this->app->bind(ResponseFormatter::class, fn () => new class implements ResponseFormatter
        {
            public function success(mixed $data, int $status = 200): JsonResponse
            {
                return response()->json(['payload' => $data], $status);
            }

            public function error(string $message, int $status, ?string $code = null): JsonResponse
            {
                return response()->json(['problem' => $code], $status);
            }
        });

        $this->createRun()->assertCreated()->assertJsonPath('payload.run.status', 'queued');
        $this->postJson('/api/runner/claim')->assertUnauthorized()->assertJsonPath('problem', 'runner_unknown');
    }

    #[Test]
    public function a_host_can_swap_the_resource_and_request(): void
    {
        config(['runs.resources.run' => TerseRunResource::class, 'runs.requests.store_run' => StrictStoreRunRequest::class]);

        $this->createRun()->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->createRun(['idempotency_key' => 'k'])->assertCreated()->assertExactJson([
            'success' => true,
            'data' => ['run' => ['status' => 'queued']],
        ]);
    }

    #[Test]
    public function runner_claims_are_off_until_the_host_enables_them(): void
    {
        $this->refreshApplicationWithRunnerRoutes(false);

        $this->claim()->assertNotFound();
    }

    private function refreshApplicationWithRunnerRoutes(bool $enabled): void
    {
        config(['runs.register_runner_routes' => $enabled]);
        $this->app['router']->setRoutes(new RouteCollection);
        (new RunsServiceProvider($this->app))->boot();
    }
}

class TerseRunResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['status' => $this->status->value];
    }
}

class StrictStoreRunRequest extends StoreRunRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'idempotency_key' => ['required', 'string']];
    }
}
