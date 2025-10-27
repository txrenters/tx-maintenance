<?php

namespace App\Http\Controllers;

use Spatie\Activitylog\Models\Activity;

class NotificationController extends Controller
{
    public function fetchNotification()
    {
        $user = auth()->user();

        $query = Activity::latest();

        if ($user->hasAnyRole(['tenant', 'owner'])) {
            $userNum = $user->phone;
            $query->where('properties->receiverNumber', $userNum);
        }

        if ($user->hasRole('vendor') && $user->vendor) {
            $vendorPhone = $user->vendor->twilio_number;
            $query->where('properties->receiverNumber', $vendorPhone);
        }

        $activities = $query->take(100)->get()->map(function ($activity) {
            return [
                'id' => $activity->id,
                'title' => $activity->description,
                'event' => $activity->event,
                'message' => $activity->properties['message']
                    ?? $activity->properties['filename']
                    ?? $activity->properties['jobber_error_message']
                    ?? $activity->description,
                'subject' => $activity->subject,
                'time' => $activity->created_at->timezone('America/Chicago')->diffForHumans(),
                'timestamp' => $activity->created_at->timestamp,
                'read' => $activity->properties['read'] ?? false,
            ];
        });

        return response()->json($activities);

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
}
