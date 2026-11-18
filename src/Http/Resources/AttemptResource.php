<?php

namespace Whilesmart\Runs\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AttemptResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status?->value,
            'executor_type' => $this->executor_type,
            'executor_id' => $this->executor_id,
            'executor_name' => $this->executor_name,
            'lease_expires_at' => $this->lease_expires_at?->toIso8601String(),
            'heartbeat_at' => $this->heartbeat_at?->toIso8601String(),
            'deadline_at' => $this->deadline_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
        ];
    }
}
