<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\JobberTextMessage;
use App\Models\JobberVisit;
use Spatie\Activitylog\Models\Activity;

class NotificationController extends Controller
{
    public function fetchNotification()
    {
        $user = auth()->user();

        // Named logs share this table but are records, not notifications: the
        // automated-messages ledger (IT Tools page) and the audit trails the
        // technician roster and template editor write — bare "created"/
        // "updated" rows that were flooding every staff bell. Only the
        // default (unnamed) log is written to be seen here.
        $query = Activity::with('subject')
            ->where(function ($q) {
                $q->whereNull('log_name')
                    ->orWhere('log_name', config('activitylog.default_log_name'));
            })
            ->latest();

        if ($user->hasAnyRole(['tenant', 'owner'])) {
            // Only their own messages; never fall back to "receiverNumber is null".
            $userNum = $user->phone;
            $userNum
                ? $query->where('properties->receiverNumber', $userNum)
                : $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('vendor')) {
            // Vendors are only notified about their own WOC <-> vendor conversation
            // (conversation_type 'vendor') on work orders assigned to them. They must
            // never see tenant/owner threads or other activity on the work order.
            $workOrderIds = $user->vendor
                ? $user->vendor->workOrders()->pluck('work_orders.id')->all()
                : [];

            if (empty($workOrderIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHasMorph(
                    'subject',
                    [Conversation::class],
                    function ($q) use ($workOrderIds) {
                        $q->where('conversation_type', 'vendor')
                            ->whereIn('work_order_id', $workOrderIds);
                    }
                );
            }
        }

        $activities = $query->take(1000)->get();

        $activities->loadMorph('subject', [
            JobberTextMessage::class => ['jobber'],
        ]);

        $result = $activities->map(function ($activity) {
            return [
                'id' => $activity->id,
                'title' => $activity->description,
                'event' => $activity->event,
                'message' => $activity->properties['message']
                    ?? $activity->properties['filename']
                    ?? $activity->properties['jobber_error_message']
                    ?? $activity->description,
                'error_code' => $activity->properties['error_code'] ?? null,
                'error_message' => $activity->properties['error_message'] ?? null,
                'twilio_status' => $activity->properties['twilio_status'] ?? null,
                'work_order_id' => $activity->properties['work_order_id'] ?? $activity->subject?->work_order_id ?? null,
                'job_id' => $this->jobIdFor($activity),
                'subject' => $this->subjectSummary($activity->subject),
                'timestamp' => $activity->created_at->timestamp,
                'read' => $activity->properties['read'] ?? false,
            ];
        });

        return response()->json($result);

    }

    /**
     * The jobbers.id the bell's Open button routes to (jobber.jobDetails).
     *
     * A visit's own jobber_id is Jobber's encoded visit id, not a job key —
     * jobber_not_sent rows hang off the visit, so they resolve through
     * jobber_job_id instead.
     */
    private function jobIdFor(Activity $activity): int|string|null
    {
        if ($activity->subject instanceof JobberVisit) {
            return $activity->subject->jobber_job_id;
        }

        return $activity->properties['job_id'] ?? $activity->subject?->jobber_id ?? null;
    }

    /**
     * Only what the bell's buttons need to identify and reopen the thread.
     * Serializing the whole subject model shipped every message body, phone
     * number and Twilio sid in the row to the browser on each poll — the
     * fat-payload serialization pattern behind earlier production
     * out-of-memory 500s, at a thousand rows per response.
     *
     * @return array<string, mixed>|null
     */
    private function subjectSummary(?object $subject): ?array
    {
        if (! $subject) {
            return null;
        }

        $jobber = method_exists($subject, 'relationLoaded') && $subject->relationLoaded('jobber')
            ? $subject->getRelation('jobber')
            : null;

        return [
            'id' => $subject->id ?? null,
            'conversation_type' => $subject->conversation_type ?? null,
            'work_order_id' => $subject->work_order_id ?? null,
            'work_order_no' => $subject->work_order_no ?? null,
            'jobber_id' => $subject->jobber_id ?? null,
            'sender_number' => $subject->sender_number ?? null,
            'receiver_number' => $subject->receiver_number ?? null,
            'jobber' => $jobber ? ['job_status' => $jobber->job_status ?? null] : null,
        ];
    }

    public function markAsRead(Activity $activity)
    {
        $props = $activity->properties ?? [];
        $props['read'] = true;

        $activity->update([
            'properties' => $props,
        ]);

        return response()->json(['message' => 'Notification marked as read']);
    }

    public function markAsUnread(Activity $activity)
    {
        $props = $activity->properties ?? [];
        $props['read'] = false;

        $activity->update([
            'properties' => $props,
        ]);

        return response()->json(['message' => 'Notification marked as unread']);
    }
}
