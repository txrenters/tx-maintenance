<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationLogsController extends Controller
{
    public function index(Request $request)
    {
        // Get all conversations with work order details
        $conversations = Conversation::with(['work_order:id,work_order_no'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Get all work orders for filter dropdown (only those that exist)
        $workOrders = WorkOrder::select('id', 'work_order_no')
            ->whereIn('id', $conversations->pluck('work_order_id')->filter()->unique())
            ->orderBy('work_order_no')
            ->get();

        // Calculate stats
        $stats = [
            'total' => $conversations->count(),
            'unread' => $conversations->where('is_read', false)->count(),
            'read' => $conversations->where('is_read', true)->count(),
            'active_conversations' => $conversations->groupBy('work_order_id')->count(),
        ];

        return inertia('ConversationLogs', [
            'title' => 'Conversation Logs',
            'conversations' => $conversations,
            'workOrders' => $workOrders,
            'stats' => $stats,
        ]);
    }
}