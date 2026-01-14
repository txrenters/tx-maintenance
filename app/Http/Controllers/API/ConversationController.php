<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\GetConversationsRequest;
use App\Http\Resources\API\ConversationResource;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;

class ConversationController extends Controller
{
    /**
     * Get conversations by phone number
     */
    public function index(GetConversationsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Normalize phone number to match database format
        $phone = $this->normalizePhoneNumber($validated['phone']);

        // Build query
        $query = Conversation::query()
            ->with(['media', 'work_order'])
            ->where(function ($q) use ($phone) {
                $q->where('sender_number', $phone)
                    ->orWhere('receiver_number', $phone);
            });

        // Apply optional filters
        if (! empty($validated['conversation_type'])) {
            $query->where('conversation_type', $validated['conversation_type']);
        }

        if (! empty($validated['work_order_id'])) {
            $query->where('work_order_id', $validated['work_order_id']);
        }

        // Order by most recent first
        $conversations = $query->orderBy('created_at', 'desc')->take(4)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'conversations' => ConversationResource::collection($conversations),
                'total' => $conversations->count(),
            ],
        ], 200);
    }

    /**
     * Normalize phone number to match database format
     */
    private function normalizePhoneNumber(string $phone): string
    {
        $cleaned = preg_replace('/\D+/', '', $phone);

        if (strlen($cleaned) === 10) {
            return '+1'.$cleaned;
        }

        if (strlen($cleaned) > 10 && str_starts_with($cleaned, '1')) {
            return '+'.$cleaned;
        }

        return $phone;
    }
}
