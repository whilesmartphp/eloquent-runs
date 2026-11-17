<?php

namespace Whilesmart\Runs\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ArtifactResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->public_id,
            'attempt_id' => $this->attempt?->public_id,
            'path' => $this->path,
            'type' => $this->type,
            'media_type' => $this->media_type,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'metadata' => $this->metadata,
            'received_at' => $this->received_at?->toIso8601String(),
        ];
    }
}
