<?php

namespace App\Http\Controllers;

use Spatie\Activitylog\Models\Activity;

class NotificationController extends Controller
{
    public function fetchNotification()
    {
        $activities = Activity::latest()
            ->take(50)
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'title' => $activity->description,
                    'message' => $activity->properties['message']
                         ?? $activity->properties['filename']
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
