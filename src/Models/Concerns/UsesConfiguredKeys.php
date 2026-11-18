<?php

namespace Whilesmart\Runs\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait UsesConfiguredKeys
{
    public function initializeUsesConfiguredKeys(): void
    {
        if (config('runs.uuids')) {
            $this->setKeyType('string');
            $this->setIncrementing(false);
        }
    }

    public static function bootUsesConfiguredKeys(): void
    {
        static::creating(function (Model $model) {
            if (config('runs.uuids') && ! $model->getKey()) {
                $model->setAttribute($model->getKeyName(), (string) Str::orderedUuid());
            }
        });
    }
}
