<?php

namespace Whilesmart\Runs\Models\Concerns;

use Illuminate\Support\Str;

trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            $model->public_id ??= strtolower((string) Str::ulid());
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
