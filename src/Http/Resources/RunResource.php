<?php

namespace Whilesmart\Runs\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RunResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            'type' => $this->type,
            'execution_mode' => $this->execution_mode?->value,
            'status' => $this->status?->value,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'trigger_type' => $this->trigger_type,
            'trigger_id' => $this->trigger_id,
            'trigger_name' => $this->trigger_name,
            'requested_by_type' => $this->requested_by_type,
            'requested_by_id' => $this->requested_by_id,
            'input' => $this->input ?? [],
            'result' => $this->result,
            'progress' => $this->progress,
            'metadata' => $this->metadata,
            'attempt_count' => $this->attempt_count,
            'max_attempts' => $this->max_attempts,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'available_at' => $this->available_at?->toIso8601String(),
            'deadline_at' => $this->deadline_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'cancel_requested_at' => $this->cancel_requested_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
