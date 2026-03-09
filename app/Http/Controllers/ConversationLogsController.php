<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\JobberTextMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConversationLogsController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $canViewJobMessages = $user && ($user->hasRole('admin') || $user->hasRole('woc'));

        $filters = [
            'search' => trim((string) $request->input('search', '')),
            'source' => (string) $request->input('source', 'all'),
            'work_order_id' => $request->filled('work_order_id') ? (string) $request->input('work_order_id') : 'all',
            'jobber_id' => $request->filled('jobber_id') ? (string) $request->input('jobber_id') : 'all',
            'read_status' => (string) $request->input('read_status', 'all'),
            'delivery_status' => (string) $request->input('delivery_status', 'all'),
        ];

        if (! in_array($filters['source'], ['all', 'work_order', 'job'], true)) {
            $filters['source'] = 'all';
        }
        if (! in_array($filters['read_status'], ['all', 'read', 'unread'], true)) {
            $filters['read_status'] = 'all';
        }

        $perPage = (int) $request->input('per_page', 50);
        $perPage = max(10, min($perPage, 100));

        $unionQuery = $this->buildMessagesUnionQuery($canViewJobMessages);

        $messagesQuery = DB::query()->fromSub($unionQuery, 'messages');
        $this->applyFilters($messagesQuery, $filters);

        $statsRow = (clone $messagesQuery)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN source = 'work_order' AND is_read = 1 THEN 1 ELSE 0 END) as read")
            ->selectRaw("SUM(CASE WHEN source = 'work_order' AND is_read = 0 THEN 1 ELSE 0 END) as unread")
            ->selectRaw("SUM(CASE WHEN source = 'work_order' THEN 1 ELSE 0 END) as work_order_total")
            ->selectRaw("SUM(CASE WHEN source = 'job' THEN 1 ELSE 0 END) as job_total")
            ->selectRaw("SUM(CASE WHEN LOWER(COALESCE(twilio_status, '')) IN ('failed', 'undelivered', 'canceled') THEN 1 ELSE 0 END) as failed")
            ->selectRaw("SUM(CASE WHEN COALESCE(twilio_status, '') <> '' THEN 1 ELSE 0 END) as with_delivery_status")
            ->selectRaw("COUNT(DISTINCT CASE
                WHEN source = 'work_order' THEN CONCAT('wo:', COALESCE(work_order_id, 0))
                WHEN source = 'job' THEN CONCAT('job:', COALESCE(jobber_id, 0))
                ELSE row_id
            END) as active_threads")
            ->first();

        $messages = $messagesQuery
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $workOrders = Conversation::query()
            ->join('work_orders as wo', 'wo.id', '=', 'work_order_conversations.work_order_id')
            ->select('wo.id', 'wo.work_order_no')
            ->distinct()
            ->orderBy('wo.work_order_no')
            ->get();

        $jobs = collect();
        if ($canViewJobMessages) {
            $jobs = JobberTextMessage::query()
                ->join('jobber_jobs as jj', 'jj.id', '=', 'jobber_text_messages.jobber_id')
                ->select('jj.id', 'jj.job_number', 'jj.title')
                ->distinct()
                ->orderBy('jj.job_number')
                ->get();
        }

        $deliveryStatuses = DB::query()
            ->fromSub($this->buildMessagesUnionQuery($canViewJobMessages), 'messages')
            ->select('twilio_status')
            ->whereNotNull('twilio_status')
            ->where('twilio_status', '<>', '')
            ->distinct()
            ->orderBy('twilio_status')
            ->pluck('twilio_status')
            ->values();

        $stats = [
            'total' => (int) ($statsRow->total ?? 0),
            'read' => (int) ($statsRow->read ?? 0),
            'unread' => (int) ($statsRow->unread ?? 0),
            'work_order_total' => (int) ($statsRow->work_order_total ?? 0),
            'job_total' => (int) ($statsRow->job_total ?? 0),
            'failed' => (int) ($statsRow->failed ?? 0),
            'with_delivery_status' => (int) ($statsRow->with_delivery_status ?? 0),
            'active_threads' => (int) ($statsRow->active_threads ?? 0),
        ];

        return inertia('ConversationLogs', [
            'title' => 'Messages',
            'messages' => $messages,
            'workOrders' => $workOrders,
            'jobs' => $jobs,
            'stats' => $stats,
            'filters' => $filters,
            'deliveryStatuses' => $deliveryStatuses,
            'canViewJobMessages' => $canViewJobMessages,
        ]);
    }

    protected function buildMessagesUnionQuery(bool $includeJobMessages)
    {
        $workOrderMessages = Conversation::query()
            ->leftJoin('work_orders as wo', 'wo.id', '=', 'work_order_conversations.work_order_id')
            ->selectRaw("CONCAT('work_order-', work_order_conversations.id) as row_id")
            ->selectRaw('work_order_conversations.id as source_id')
            ->selectRaw("'work_order' as source")
            ->selectRaw('work_order_conversations.message as message')
            ->selectRaw('work_order_conversations.sender_number as sender_number')
            ->selectRaw('work_order_conversations.receiver_number as receiver_number')
            ->selectRaw('work_order_conversations.created_at as created_at')
            ->selectRaw('work_order_conversations.is_read as is_read')
            ->selectRaw('work_order_conversations.work_order_id as work_order_id')
            ->selectRaw('wo.work_order_no as work_order_no')
            ->selectRaw('NULL as jobber_id')
            ->selectRaw('NULL as job_number')
            ->selectRaw('work_order_conversations.twilio_sid as twilio_sid')
            ->selectRaw("NULLIF(work_order_conversations.twilio_status, '') as twilio_status")
            ->selectRaw('work_order_conversations.twilio_error_code as twilio_error_code')
            ->selectRaw('work_order_conversations.twilio_error_message as twilio_error_message')
            ->selectRaw("NULLIF(work_order_conversations.conversation_type, '') as message_type");

        $unionQuery = $workOrderMessages->toBase();

        if (! $includeJobMessages) {
            return $unionQuery;
        }

        $hasTwilioSid = Schema::hasColumn('jobber_text_messages', 'twilio_sid');
        $hasTwilioStatus = Schema::hasColumn('jobber_text_messages', 'twilio_status');
        $hasTwilioErrorCode = Schema::hasColumn('jobber_text_messages', 'twilio_error_code');
        $hasTwilioErrorMessage = Schema::hasColumn('jobber_text_messages', 'twilio_error_message');
        $hasStatus = Schema::hasColumn('jobber_text_messages', 'status');
        $hasErrorMessage = Schema::hasColumn('jobber_text_messages', 'error_message');

        $jobTwilioSidExpr = $hasTwilioSid ? 'jtm.twilio_sid' : 'NULL';
        $jobTwilioStatusExpr = $hasTwilioStatus
            ? ($hasStatus ? "COALESCE(NULLIF(jtm.twilio_status, ''), NULLIF(jtm.status, ''))" : "NULLIF(jtm.twilio_status, '')")
            : ($hasStatus ? "NULLIF(jtm.status, '')" : 'NULL');
        $jobTwilioErrorCodeExpr = $hasTwilioErrorCode ? 'jtm.twilio_error_code' : 'NULL';
        $jobTwilioErrorMessageExpr = $hasTwilioErrorMessage
            ? ($hasErrorMessage ? "COALESCE(NULLIF(jtm.twilio_error_message, ''), NULLIF(jtm.error_message, ''))" : "NULLIF(jtm.twilio_error_message, '')")
            : ($hasErrorMessage ? "NULLIF(jtm.error_message, '')" : 'NULL');

        $jobMessages = DB::table('jobber_text_messages as jtm')
            ->leftJoin('jobber_jobs as jj', 'jj.id', '=', 'jtm.jobber_id')
            ->selectRaw("CONCAT('job-', jtm.id) as row_id")
            ->selectRaw('jtm.id as source_id')
            ->selectRaw("'job' as source")
            ->selectRaw('jtm.messages as message')
            ->selectRaw('jtm.sender_number as sender_number')
            ->selectRaw('jtm.receiver_number as receiver_number')
            ->selectRaw('jtm.created_at as created_at')
            ->selectRaw('NULL as is_read')
            ->selectRaw('NULL as work_order_id')
            ->selectRaw('NULL as work_order_no')
            ->selectRaw('jtm.jobber_id as jobber_id')
            ->selectRaw('jj.job_number as job_number')
            ->selectRaw($jobTwilioSidExpr.' as twilio_sid')
            ->selectRaw($jobTwilioStatusExpr.' as twilio_status')
            ->selectRaw($jobTwilioErrorCodeExpr.' as twilio_error_code')
            ->selectRaw($jobTwilioErrorMessageExpr.' as twilio_error_message')
            ->selectRaw("'job_text' as message_type");

        return $unionQuery->unionAll($jobMessages);
    }

    protected function applyFilters($query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $like = '%'.$search.'%';

            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(COALESCE(message, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(sender_number, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(receiver_number, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(work_order_no, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(job_number, "")) LIKE ?', [$like]);
            });
        }

        if (($filters['source'] ?? 'all') !== 'all') {
            $query->where('source', $filters['source']);
        }

        if (($filters['work_order_id'] ?? 'all') !== 'all') {
            $query->where('work_order_id', (int) $filters['work_order_id']);
        }

        if (($filters['jobber_id'] ?? 'all') !== 'all') {
            $query->where('jobber_id', (int) $filters['jobber_id']);
        }

        if (($filters['read_status'] ?? 'all') === 'read') {
            $query->where('source', 'work_order')->where('is_read', 1);
        } elseif (($filters['read_status'] ?? 'all') === 'unread') {
            $query->where('source', 'work_order')->where('is_read', 0);
        }

        if (($filters['delivery_status'] ?? 'all') !== 'all') {
            $deliveryStatus = mb_strtolower((string) $filters['delivery_status']);
            $query->whereRaw('LOWER(COALESCE(twilio_status, "")) = ?', [$deliveryStatus]);
        }
    }
}
