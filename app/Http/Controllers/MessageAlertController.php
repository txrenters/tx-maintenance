<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Feeds the chime and desktop notification for newly arrived messages.
 *
 * The browser polls this with the id of the newest message it has already
 * alerted on, so the response only ever carries genuinely new arrivals. The
 * first call of a session sends no cursor and gets back only the current high
 * water mark, so opening the app never replays a backlog.
 */
class MessageAlertController extends Controller
{
    /** A burst bigger than this is summarised rather than announced one by one. */
    private const MAX_ALERTS = 5;

    private const PREVIEW_LENGTH = 140;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->hasAnyRole(['admin', 'woc'])) {
            return response()->json(['latest_id' => 0, 'alerts' => [], 'new_count' => 0]);
        }

        $validated = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $inbound = $this->inboundForUser($user->id);

        $latestId = (int) (clone $inbound)->max('work_order_conversations.id');

        // No cursor yet: this is a fresh page load, so just hand back the mark.
        if (! $request->filled('after_id')) {
            return response()->json([
                'latest_id' => $latestId,
                'alerts' => [],
                'new_count' => 0,
            ]);
        }

        $afterId = (int) $validated['after_id'];

        $newCount = (clone $inbound)->where('work_order_conversations.id', '>', $afterId)->count();

        $alerts = (clone $inbound)
            ->where('work_order_conversations.id', '>', $afterId)
            ->orderByDesc('work_order_conversations.id')
            ->limit(self::MAX_ALERTS)
            ->get([
                'work_order_conversations.id',
                'work_order_conversations.message',
                'work_order_conversations.is_mms',
                'work_order_conversations.conversation_type',
                'work_order_conversations.vendor_id',
                'work_order_conversations.owner_id',
                'work_order_conversations.sender_number',
                'work_order_conversations.work_order_id',
            ])
            ->map(fn (Conversation $message) => [
                'id' => $message->id,
                'work_order_id' => $message->work_order_id,
                'work_order_no' => $message->work_order?->work_order_no,
                'conversation_type' => $message->conversation_type,
                'vendor_id' => $message->vendor_id,
                'owner_id' => $message->owner_id,
                'party' => $this->partyLabel($message->conversation_type),
                'from' => $message->sender_number,
                'preview' => $this->preview($message),
            ])
            ->values();

        return response()->json([
            'latest_id' => max($latestId, $afterId),
            'alerts' => $alerts,
            'new_count' => $newCount,
        ]);
    }

    /**
     * Messages that arrived from the outside on work orders this user
     * coordinates.
     *
     * is_read is the de facto direction column — outbound writers set it true on
     * insert, inbound writers leave it false. Work orders with no coordinator
     * assigned fall to everyone, so nothing arrives unheard.
     */
    private function inboundForUser(int $userId)
    {
        return Conversation::query()
            ->with('work_order:id,work_order_no')
            ->join('work_orders as wo', 'wo.id', '=', 'work_order_conversations.work_order_id')
            ->where('work_order_conversations.is_read', false)
            ->where(fn ($query) => $query
                ->where('wo.user_id', $userId)
                ->orWhereNull('wo.user_id')
            );
    }

    private function preview(Conversation $message): string
    {
        $body = trim(preg_replace('/\s+/', ' ', (string) $message->message));

        if ($body === '') {
            return $message->is_mms ? 'Sent an attachment' : '';
        }

        return mb_strimwidth($body, 0, self::PREVIEW_LENGTH, '…');
    }

    private function partyLabel(?string $conversationType): string
    {
        return match ($conversationType) {
            'tenant' => 'Tenant',
            'owner' => 'Owner',
            'vendor' => 'Vendor',
            'vendor_tenant' => 'Vendor–Tenant',
            'vendor_owner' => 'Vendor–Owner',
            default => 'Message',
        };
    }
}
