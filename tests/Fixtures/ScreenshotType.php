<?php

namespace Tests\Fixtures;

use Whilesmart\Runs\Contracts\RunType;

class ScreenshotType implements RunType
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
