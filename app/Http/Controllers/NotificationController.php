<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\JobberTextMessage;

class NotificationController extends Controller
{
    public function fetchNotification()
    {
        // Get latest 10 conversations (both read and unread)
        $workOrderText = Conversation::with('work_order:id,work_order_no') // select only needed fields
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => 'Work Order #'.$item->work_order->work_order_no.' - New Text Message',
                    'message' => $item->message,
                    'time' => $item->created_at->timezone('America/Chicago')->diffForHumans(),
                    'timestamp' => $item->created_at->timestamp, // raw for sorting
                    'read' => (bool) $item->is_read, // Include read status
                ];
            })->toArray();

        $jobberText = JobberTextMessage::with('jobber.clientContacts') // select only needed fields
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'title' => 'Job #'.$item->jobber->job_number.' - New Text Message',
                    'message' => $item->messages,
                    'time' => $item->created_at->timezone('America/Chicago')->diffForHumans(),
                    'timestamp' => $item->created_at->timestamp, // raw for sorting
                    'read' => false, 
                ];
            })->toArray();

        
        $notifications = array_merge($workOrderText, $jobberText);

        usort($notifications, function ($a, $b) {
            return $b['timestamp'] <=> $a['timestamp']; // latest first
        });

        return response()->json($notifications);

    }

    public function markAsRead(Conversation $message)
    {
        $message->update([
            'is_read' => true,
        ]);

        return response()->json(['message' => 'Notification marked as read']);

    }
}
