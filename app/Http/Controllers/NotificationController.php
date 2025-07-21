<?php

namespace App\Http\Controllers;

use App\Models\Conversation;

class NotificationController extends Controller
{
    public function fetchNotification()
    {
        // Get latest 10 conversations (both read and unread)
        $convos = Conversation::with('work_order:id,work_order_no') // select only needed fields
            ->orderBy('created_at', 'desc')
            ->limit(30)
            ->get();

        $notifications = $convos->map(function ($item) {
            return [
                'id' => $item->id,
                'title' => '#'.$item->work_order->work_order_no.' - New Text Message',
                'message' => $item->message,
                'time' => $item->created_at->timezone('America/Chicago')->diffForHumans(),
                'read' => (bool) $item->is_read, // Include read status
            ];
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
