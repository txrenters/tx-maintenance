<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\JobberTextMessage;
use App\Services\AutomatedMessageLogService;
use Spatie\Activitylog\Models\Activity;

class NotificationController extends Controller
{
    public function fetchNotification()
    {
        $user = auth()->user();

        // The automated-messages ledger (IT Tools page) shares this table but
        // is a log, not a staff notification — keep it out of the bell.
        $query = Activity::with('subject')
            ->where(function ($q) {
                $q->whereNull('log_name')
                    ->orWhere('log_name', '!=', AutomatedMessageLogService::LOG_NAME);
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

        $activities = $query->take(100)->get();

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
                'job_id' => $activity->properties['job_id'] ?? $activity->subject?->jobber_id ?? null,
                'subject' => $activity->subject,
                'time' => $activity->created_at->timezone('America/Chicago')->diffForHumans(),
                'timestamp' => $activity->created_at->timestamp,
                'read' => $activity->properties['read'] ?? false,
            ];
        });

        return response()->json($result);

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
