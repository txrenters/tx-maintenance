<?php

namespace App\Http\Resources\API;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationMediaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_url' => $this->original_url,
            'local_url' => $this->public_url,
            'content_type' => $this->content_type,
            'file_name' => $this->file_name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
