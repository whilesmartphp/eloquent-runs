<?php

namespace Whilesmart\Runs\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Whilesmart\Runs\Enums\ExecutionMode;
use Whilesmart\Runs\Enums\RunStatus;
use Whilesmart\Runs\Models\Run;

class RunFactory extends Factory
{
    protected $model = Run::class;

    public function definition(): array
    {
        return [
            'owner_type' => 'App\\Models\\Workspace',
            'owner_id' => 1,
            'type' => 'example',
            'execution_mode' => ExecutionMode::Pull,
            'status' => RunStatus::Queued,
            'input' => [],
            'max_attempts' => 1,
            'lease_seconds' => 90,
            'attempt_timeout_seconds' => 3600,
            'available_at' => now(),
        ];
    }
}
