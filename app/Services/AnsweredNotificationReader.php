<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Clears the notification bell for a thread once a person on our team has
 * replied in it, from the work order page or in Chat Support (tx-chatbot),
 * and stamps the answered messages so the Inbox reads them as read and
 * answered even when the reply was filed under another owner or vendor.
 *
 * A bell entry otherwise stays unread until someone clicks it, even after the
 * message was answered elsewhere. Only the thread's earlier inbound messages
 * (and owner approve/disapprove clicks) are cleared; automated texts never
 * call this, so a message nobody answered stays unread.
 */
class AnsweredNotificationReader
{
    /**
     * Mark the messages the reply answers, and their bell entries, as read.
     *
     * @return int how many bell entries were marked read
     */
    public function markAnsweredBy(Conversation $reply): int
    {
        $inboundIds = $this->answeredInboundIds($reply);

        if ($inboundIds->isEmpty()) {
            return 0;
        }

        Conversation::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $inboundIds)
            ->whereNull('answered_at')
            ->update(['answered_at' => now()]);

        app(AwaitingReplyCounter::class)->forget();

        $messages = Activity::query()
            ->where('event', 'work_order_message_received')
            ->where('subject_type', (new Conversation)->getMorphClass())
            ->whereIn('subject_id', $inboundIds)
            ->get();

        $approvals = $reply->conversation_type === 'owner'
            ? Activity::query()
                ->where('event', 'owner_portal_approval')
                ->where('subject_type', (new WorkOrder)->getMorphClass())
                ->where('subject_id', $reply->work_order_id)
                ->get()
                ->filter(fn (Activity $activity): bool => $inboundIds->contains((int) ($activity->properties['conversation_id'] ?? 0)))
            : collect();

        $marked = 0;

        foreach ($messages->concat($approvals) as $activity) {
            if ($activity->properties['read'] ?? false) {
                continue;
            }

            $properties = $activity->properties ?? [];
            $properties['read'] = true;
            $activity->update(['properties' => $properties]);
            $marked++;
        }

        return $marked;
    }

    /**
     * Stamp answered_at on every waiting message that already has a reply
     * after it, matched as answeredInboundIds matches a new reply. For the
     * messages answered before replies stamped them; it reads every outbound
     * row as a reply, since older rows do not say who sent them.
     *
     * @return int how many messages were stamped
     */
    public function backfillAnswered(): int
    {
        $ids = DB::table('work_order_conversations as inbound')
            ->where('inbound.is_read', false)
            ->whereNull('inbound.answered_at')
            ->whereExists(fn ($replies) => $replies
                ->select(DB::raw(1))
                ->from('work_order_conversations as reply')
                ->whereColumn('reply.work_order_id', 'inbound.work_order_id')
                ->whereColumn('reply.conversation_type', 'inbound.conversation_type')
                ->whereColumn('reply.id', '>', 'inbound.id')
                ->where('reply.is_read', true)
                ->where(fn ($owner) => $owner->whereNull('reply.owner_id')->orWhereNull('inbound.owner_id')->orWhereColumn('reply.owner_id', 'inbound.owner_id'))
                ->where(fn ($vendor) => $vendor->whereNull('reply.vendor_id')->orWhereNull('inbound.vendor_id')->orWhereColumn('reply.vendor_id', 'inbound.vendor_id')))
            ->pluck('inbound.id');

        foreach ($ids->chunk(1000) as $chunk) {
            DB::table('work_order_conversations')->whereIn('id', $chunk)->update(['answered_at' => now()]);
        }

        app(AwaitingReplyCounter::class)->forget();

        return $ids->count();
    }

    /**
     * The inbound messages before the reply in the same thread. With several
     * owners or vendors on a work order, a reply to one leaves the others'
     * messages alone; messages not tied to anyone are cleared by any reply.
     *
     * @return Collection<int, int>
     */
    private function answeredInboundIds(Conversation $reply): Collection
    {
        return Conversation::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $reply->work_order_id)
            ->where('conversation_type', $reply->conversation_type)
            ->where('id', '<', $reply->id)
            ->where('is_read', false)
            ->when($reply->owner_id, fn ($query) => $query->where(fn ($query) => $query->whereNull('owner_id')->orWhere('owner_id', $reply->owner_id)))
            ->when($reply->vendor_id, fn ($query) => $query->where(fn ($query) => $query->whereNull('vendor_id')->orWhere('vendor_id', $reply->vendor_id)))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
    }
}
