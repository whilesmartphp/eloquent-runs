<?php

namespace Whilesmart\Runs\Support;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Whilesmart\Runs\Contracts\RunType;

class RunTypeRegistry
{
    /** @var array<string, class-string<RunType>|RunType> */
    private array $types;

    public function __construct(private readonly Container $container)
    {
        $this->types = (array) config('runs.types', []);
    }

    /** @param class-string<RunType>|RunType $type */
    public function register(string $name, string|RunType $type): void
    {
        $this->types[$name] = $type;
    }

    public function has(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->types);
    }

    public function get(string $name): RunType
    {
        $type = $this->types[$name] ?? throw new InvalidArgumentException("Unknown run type [{$name}].");

        if (is_string($type)) {
            $type = $this->container->make($type);
        }

        if (! $type instanceof RunType) {
            throw new InvalidArgumentException("Run type [{$name}] must implement ".RunType::class.'.');
        }

        return $type;
    }
}
