<?php

namespace Tests\Fixtures;

use Whilesmart\Runs\Http\Requests\StoreRunRequest;

class FingerprintedStoreRequest extends StoreRunRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['request_fingerprint' => hash('sha256', json_encode($this->input('input'), JSON_THROW_ON_ERROR))]);
    }

    public function rules(): array
    {
        return [...parent::rules(), 'request_fingerprint' => ['required', 'string']];
    }
}
