<?php

namespace Tests\Fixtures;

use Whilesmart\Runs\Contracts\HandlesRun;
use Whilesmart\Runs\Contracts\RunType;
use Whilesmart\Runs\Exceptions\RunFailed;
use Whilesmart\Runs\Models\Run;
use Whilesmart\Runs\Models\RunAttempt;

class AddNumbersType implements HandlesRun, RunType
{
    public static int $calls = 0;

    public function inputRules(): array
    {
        return ['numbers' => ['required', 'array'], 'fail_until' => ['nullable', 'integer']];
    }

    public function resultRules(): array
    {
        return ['sum' => ['required', 'integer']];
    }

    public function isRetryable(?string $errorCode): bool
    {
        return $errorCode === 'flaky';
    }

    public function handle(Run $run, RunAttempt $attempt): array
    {
        self::$calls++;

        if ($attempt->number <= ($run->input['fail_until'] ?? 0)) {
            throw new RunFailed('flaky', 'Not this time.');
        }

        return ['sum' => array_sum($run->input['numbers'])];
    }
}
