<?php

namespace Whilesmart\Runs\Exceptions;

use DateTimeInterface;
use RuntimeException;

/**
 * Thrown by a queue handler to park its run, for example until a person
 * answers. The run resumes when the host calls resume, or at resumeAt.
 */
class RunWaits extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?array $state = null,
        public readonly ?DateTimeInterface $resumeAt = null,
    ) {
        parent::__construct($reason);
    }
}
