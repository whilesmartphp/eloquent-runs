<?php

namespace Whilesmart\Runs\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Whilesmart\OwnerAccess\Concerns\AuthorizesOwnerRequest;
use Whilesmart\Runs\Enums\ExecutionMode;
use Whilesmart\Runs\Support\RunTypeRegistry;

class StoreRunRequest extends FormRequest
{
    use AuthorizesOwnerRequest;

    public function authorize(): bool
    {
        return $this->authorizeOwnerInRequest();
    }

    public function rules(): array
    {
        return [
            'owner_type' => ['required', 'string'],
            'owner_id' => ['required'],
            'type' => ['required', 'string', Rule::in(app(RunTypeRegistry::class)->names())],
            'execution_mode' => ['nullable', Rule::in(ExecutionMode::values())],
            'input' => ['nullable', 'array'],
            'subject_type' => ['nullable', 'string', 'required_with:subject_id'],
            'subject_id' => ['nullable', 'required_with:subject_type'],
            'trigger_type' => ['nullable', 'string', 'required_with:trigger_id'],
            'trigger_id' => ['nullable', 'required_with:trigger_type'],
            'trigger_name' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:200'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:20'],
            'lease_seconds' => ['nullable', 'integer', 'min:10', 'max:3600'],
            'attempt_timeout_seconds' => ['nullable', 'integer', 'min:10', 'max:86400'],
            'deadline_at' => ['nullable', 'date', 'after:now'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
