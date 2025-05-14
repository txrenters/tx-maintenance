<?php

namespace App\Http\Controllers;

use App\Models\Conversation;

class NotificationController extends Controller
{
    public function fetchNotification()
    {
        $convos = Conversation::with('work_order:id,work_order_no') // select only needed fields
            ->where('is_read', 0)
            ->orderBy('created_at', 'desc')
            ->get();

         $notifications = $convos->map(function ($item) {
            return [
                'id' => $item->id,
                'title' => '#'.$item->work_order->work_order_no.' - New Text Message' ,
                'message' => $item->message,
                'time' => $item->created_at->timezone('America/Chicago')->diffForHumans(),
            ];
        });
        
        return response()->json($notifications);

    }

    public function markAsRead(Conversation $message)
    {
        $message->update([
            'is_read' => true
        ]);
        
        return response()->json(['message' => 'Notification marked as read']);

    }
}
