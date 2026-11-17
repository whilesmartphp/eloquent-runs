<?php

namespace Tests\Fixtures;

use Whilesmart\Runs\Contracts\HandlesRun;
use Whilesmart\Runs\Contracts\RunType;
use Whilesmart\Runs\Exceptions\RunWaits;
use Whilesmart\Runs\Models\Run;
use Whilesmart\Runs\Models\RunAttempt;

/** Asks a question, waits for the answer, then finishes with it. */
class AskType implements HandlesRun, RunType
{
    public function inputRules(): array
    {
        return ['question' => ['required', 'string']];
    }

    public function resultRules(): array
    {
        return ['answer' => ['required', 'string']];
    }

    public function isRetryable(?string $errorCode): bool
    {
        return false;
    }

    public function handle(Run $run, RunAttempt $attempt): array
    {
        $answer = $run->state['answer'] ?? null;
        if ($answer === null) {
            throw new RunWaits('needs_answer', ['asked' => $run->input['question']]);
        }

        return ['answer' => $answer];
    }
}
