<?php

namespace App\Http\Resources\API;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
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
            'message' => $this->message,
            'sender_number' => $this->sender_number,
            'receiver_number' => $this->receiver_number,
            'work_order_id' => $this->work_order_id,
            'conversation_type' => $this->conversation_type,
            'is_mms' => (bool) $this->is_mms,
            'is_read' => (bool) $this->is_read,
            'media' => ConversationMediaResource::collection($this->whenLoaded('media')),
            'work_order' => $this->when($this->relationLoaded('work_order'), function () {
                return [
                    'id' => $this->work_order->id,
                    'work_order_no' => $this->work_order->work_order_no,
                    'title' => $this->work_order->title,
                    'status' => $this->work_order->status,
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
