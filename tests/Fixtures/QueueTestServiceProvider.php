<?php

namespace Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

class QueueTestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
