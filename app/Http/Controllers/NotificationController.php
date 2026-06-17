<?php

namespace App\Http\Controllers;

use App\Models\JobberTextMessage;
use Spatie\Activitylog\Models\Activity;

class NotificationController extends Controller
{
    public function fetchNotification()
    {
        $user = auth()->user();

        $query = Activity::with('subject')->latest();

        if ($user->hasAnyRole(['tenant', 'owner'])) {
            // Only their own messages; never fall back to "receiverNumber is null".
            $userNum = $user->phone;
            $userNum
                ? $query->where('properties->receiverNumber', $userNum)
                : $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('vendor')) {
            // Scope to this vendor's assigned work orders and to messages to/from
            // their own phone. Never leak unrelated activity (the old filter matched
            // a null twilio_number, which surfaced everyone's notifications).
            $workOrderIds = $user->vendor
                ? $user->vendor->workOrders()->pluck('work_orders.id')->all()
                : [];

            $phone = preg_replace('/\D+/', '', (string) ($user->vendor?->phone ?? $user->phone));
            $phoneDigits = strlen($phone) >= 10 ? substr($phone, -10) : null;

            $query->where(function ($q) use ($workOrderIds, $phoneDigits) {
                $scoped = false;

                if (! empty($workOrderIds)) {
                    $q->whereIn('properties->work_order_id', $workOrderIds);
                    $scoped = true;
                }

                if ($phoneDigits) {
                    $q->orWhere('properties->receiverNumber', 'like', '%'.$phoneDigits)
                        ->orWhere('properties->senderNumber', 'like', '%'.$phoneDigits);
                    $scoped = true;
                }

                if (! $scoped) {
                    $q->whereRaw('1 = 0');
                }
            });
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
