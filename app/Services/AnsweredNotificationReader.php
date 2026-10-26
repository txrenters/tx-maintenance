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
 * call this, so a message nobody answered stays unread. The one
 * exception is answerDecisionsBefore(): an owner's portal decision is acted
 * on, not answered, so any later outbound owner message on the work order,
 * automated or not, stamps it answered without touching the bell.
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
     * An owner's portal decision ("Approved." / "Not approved.") is acted on,
     * not answered: once any later owner message leaves on that work order,
     * to any of its owners, from staff or an automation, the decision no
     * longer waits on anyone. Stamps the work order's earlier decisions as
     * answered, leaving their bell entries alone so the coordinator still
     * sees who decided what (business, 2026-10-27; WO #44435's "Approved."
     * sat under Awaiting reply while the vendor-assignment text went to the
     * co-owner). Called from Conversation::booted for every new row.
     */
    public function answerDecisionsBefore(Conversation $outbound): void
    {
        if ($outbound->conversation_type !== 'owner' || ! $outbound->is_read) {
            return;
        }

        $decisionIds = $this->decisionConversationIds($outbound->work_order_id);

        if ($decisionIds->isEmpty()) {
            return;
        }

        $stamped = Conversation::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $decisionIds)
            ->where('id', '<', $outbound->id)
            ->whereNull('answered_at')
            ->update(['answered_at' => now()]);

        if ($stamped > 0) {
            app(AwaitingReplyCounter::class)->forget();
        }
    }

    /**
     * One-time cleanup for the decisions made before answerDecisionsBefore
     * existed: every portal decision that has a later owner message on its
     * work order is stamped answered. A database update and nothing else: no
     * text, no email, no bell change, whatever the work order's state.
     *
     * @return int how many decisions were stamped
     */
    public function backfillAnsweredDecisions(): int
    {
        $decisionIds = $this->decisionConversationIds();

        if ($decisionIds->isEmpty()) {
            return 0;
        }

        $ids = DB::table('work_order_conversations as decision')
            ->whereIn('decision.id', $decisionIds)
            ->whereNull('decision.answered_at')
            ->whereExists(fn ($replies) => $replies
                ->select(DB::raw(1))
                ->from('work_order_conversations as reply')
                ->whereColumn('reply.work_order_id', 'decision.work_order_id')
                ->where('reply.conversation_type', 'owner')
                ->where('reply.is_read', true)
                ->whereColumn('reply.id', '>', 'decision.id'))
            ->pluck('decision.id');

        foreach ($ids->chunk(1000) as $chunk) {
            DB::table('work_order_conversations')->whereIn('id', $chunk)->update(['answered_at' => now()]);
        }

        app(AwaitingReplyCounter::class)->forget();

        return $ids->count();
    }

    /**
     * The conversation rows the owner portal wrote for approve/disapprove
     * clicks, from the bell entries that record them; every work order when
     * none is given.
     *
     * @return Collection<int, int>
     */
    private function decisionConversationIds(?int $workOrderId = null): Collection
    {
        return Activity::query()
            ->where('event', 'owner_portal_approval')
            ->when($workOrderId !== null, fn ($query) => $query
                ->where('subject_type', (new WorkOrder)->getMorphClass())
                ->where('subject_id', $workOrderId))
            ->get(['properties'])
            ->map(fn (Activity $activity): int => (int) ($activity->properties['conversation_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();
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
