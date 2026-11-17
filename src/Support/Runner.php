<?php

namespace Whilesmart\Runs\Support;

class Runner
{
    /**
     * @param  string|null  $type  Morph type of the model behind the machine, if any.
     * @param  string|null  $id  Key of that model.
     * @param  array<int, string>|null  $types  Run types it accepts; null accepts every type.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $type = null,
        public readonly ?string $id = null,
        public readonly ?array $types = null,
    ) {}
}
