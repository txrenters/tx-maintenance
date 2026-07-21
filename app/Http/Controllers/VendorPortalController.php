<?php

namespace App\Http\Controllers;

use App\Http\Controllers\API\ServiceScheduleController;
use App\Http\Controllers\API\TaskController;
use App\Jobs\NotifyOperationAccountingOfTurnoverInvoice;
use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\Conversation;
use App\Models\ConversationMedia;
use App\Models\Invoice;
use App\Models\ServiceSchedule;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderTask;
use App\Models\WorkOrderVendor;
use App\Services\PropertyWareService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorPortalController extends Controller
{
    /**
     * Render the no-login dashboard listing all of a vendor's active work orders.
     * Each card links to that work order's own per-assignment portal link.
     */
    public function dashboard(Request $request)
    {
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');

        $workOrders = $vendor->workOrders()
            ->with(['service_status', 'building', 'tasks'])
            ->where('work_orders.status', 'Open')
            ->orderByDesc('created_date')
            ->get();

        $cards = $workOrders->map(function (WorkOrder $workOrder) use ($vendor) {
            $token = $workOrder->pivot->access_token;

            $unread = Conversation::query()
                ->where('work_order_id', $workOrder->id)
                ->where('conversation_type', 'vendor')
                ->where('read_by_vendor', false)
                ->forVendorThread($vendor)
                ->count();

            // Match the system: a vendor's card only reflects their own tasks.
            $ownTasks = $workOrder->tasks->where('assigned_user_id', $vendor->user_id);

            return [
                'work_order_no' => $workOrder->work_order_no,
                'description' => $workOrder->description,
                'priority' => $workOrder->priority,
                'location' => $workOrder->location,
                'category' => $workOrder->category,
                'building' => $workOrder->building?->name,
                'created_date' => $workOrder->created_date,
                'service_status_id' => $workOrder->service_status_id,
                'status' => $workOrder->service_status?->name ?? $workOrder->status,
                'is_emergency' => (bool) $workOrder->is_emergency,
                'completed_tasks' => $ownTasks->where('status', 'completed')->count(),
                'total_tasks' => $ownTasks->count(),
                'accent' => $this->cardAccent($workOrder, $ownTasks),
                'unread' => $unread,
                'url' => $workOrder->vendorPortalUrl($token),
            ];
        })->filter(fn ($card) => $card['url'] !== null)->values();

        return inertia('VendorPortal/Dashboard', [
            'title' => 'My Work Orders',
            'vendorName' => $vendor->name,
            'loginUrl' => route('login'),
            'workOrders' => $cards,
        ]);
    }

    /**
     * Render the no-login vendor portal for a single work-order assignment.
     *
     * Everything shown is strictly scoped to this vendor so they never see
     * other vendors assigned to the same work order.
     */
    public function show(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');
        /** @var WorkOrderVendor $assignment */
        $assignment = $request->attributes->get('portal_assignment');

        $workOrder->load(['service_status', 'building', 'tasks', 'woc.wocNumber.twilioPhoneNumber']);

        // The vendor's own numbers, used to attribute each message to them vs the coordinator.
        $vendorNumberDigits = collect([$vendor->twilio_number, $vendor->user?->phone])
            ->map(fn ($number) => Conversation::lastTenDigits($number))
            ->filter()
            ->unique();

        // Only this vendor's thread with the coordinator: messages tagged to this
        // vendor, plus legacy messages matched by their phone number.
        $messages = Conversation::query()
            ->with('media')
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'vendor')
            ->forVendorThread($vendor)
            ->orderBy('created_at')
            ->get();

        $unreadMessages = $messages->where('read_by_vendor', false)->count();

        // Office-provided "before" photos plus anything this vendor uploaded themselves.
        $attachments = Attachments::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where(function ($q) use ($vendor) {
                $q->where('type', 'before')
                    ->orWhere('user_id', $vendor->user_id);
            })
            ->latest()
            ->get(['id', 'title', 'type', 'filename', 'filetype', 'created_at']);

        // This vendor's own invoices for this work order.
        $invoices = Invoice::withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->latest()
            ->get(['id', 'title', 'amount', 'status', 'filename', 'created_at']);

        // This vendor's own service schedules for this work order.
        $schedules = ServiceSchedule::withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('scheduled_date')
            ->get(['id', 'title', 'description', 'scheduled_date', 'scheduled_end_date', 'status']);

        return inertia('VendorPortal/Show', [
            'title' => 'Work Order #'.$workOrder->work_order_no,
            'token' => $assignment->access_token,
            'dashboardUrl' => route('vendor.portal.dashboard', $vendor->ensurePortalToken()),
            'vendorName' => $vendor->name,
            'wocName' => $workOrder->woc?->name,
            'workOrder' => [
                'id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'description' => $workOrder->description,
                'priority' => $workOrder->priority,
                'location' => $workOrder->location,
                'status' => $workOrder->service_status?->name ?? $workOrder->status,
                'is_emergency' => (bool) $workOrder->is_emergency,
                'created_date' => $workOrder->created_date,
                'building' => $workOrder->building
                    ? [
                        'name' => $workOrder->building->name ?? null,
                        'address' => $workOrder->building->address ?? null,
                    ]
                    : null,
            ],
            // Match the system: a vendor only sees tasks assigned to their own user.
            'tasks' => $workOrder->tasks
                ->where('assigned_user_id', $vendor->user_id)
                ->map(fn ($task) => [
                    'id' => $task->id,
                    'name' => $task->name ?? $task->title ?? $task->description ?? null,
                    'status' => $task->status,
                ])
                ->values(),
            'estimate' => [
                'cost_estimate' => $assignment->cost_estimate,
                'time_estimate' => $assignment->time_estimate,
                'scheduled_end_date' => $assignment->scheduled_end_date,
            ],
            'attachments' => $attachments->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'type' => $a->type,
                'url' => asset('storage/'.$a->filename),
                'is_image' => str_starts_with((string) $a->filetype, 'image/')
                    || (bool) preg_match('/\.(jpe?g|png|gif|webp)$/i', (string) $a->filename),
            ])->values(),
            'invoices' => $invoices->map(fn ($inv) => [
                'id' => $inv->id,
                'title' => $inv->title,
                'amount' => $inv->amount,
                'status' => $inv->status,
                'url' => asset('storage/'.$inv->filename),
            ])->values(),
            'schedules' => $schedules->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'description' => $s->description,
                'scheduled_date' => $s->scheduled_date,
                'scheduled_end_date' => $s->scheduled_end_date,
                'status' => $s->status,
            ])->values(),
            'unreadMessages' => $unreadMessages,
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'message' => $m->message,
                'is_from_vendor' => $m->sender_number === 'portal'
                    || $vendorNumberDigits->contains(Conversation::lastTenDigits($m->sender_number)),
                'created_at' => $m->created_at,
                'media' => $m->media->map(fn ($media) => [
                    'url' => $media->public_url,
                    'content_type' => $media->content_type,
                    'file_name' => $media->file_name,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * Save the vendor's cost / time estimate and target completion date.
     */
    public function updateEstimate(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');

        $validated = $request->validate([
            'cost_estimate' => 'required|numeric|min:0',
            'time_estimate' => 'nullable|integer|min:0',
            'scheduled_end_date' => 'nullable|date',
        ]);

        try {
            $workOrder->vendors()->updateExistingPivot($vendor->id, [
                'cost_estimate' => $validated['cost_estimate'],
                'time_estimate' => $validated['time_estimate'] ?? null,
                'scheduled_end_date' => $validated['scheduled_end_date'] ?? null,
            ]);

            return back()->with('success', 'Your estimate has been saved.');
        } catch (\Throwable $e) {
            Log::error('Vendor portal estimate update failed', [
                'work_order_id' => $workOrder->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Could not save your estimate. Please try again.']);
        }
    }

    /**
     * Upload one or more photos / screenshots and sync them to PropertyWare.
     */
    public function uploadAttachments(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'nullable|in:before,after,attachment',
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx,txt|max:51200',
        ]);

        try {
            $type = $validated['type'] ?? 'after';

            foreach ($request->file('files') as $file) {
                $attachment = Attachments::create([
                    'title' => $validated['title'],
                    'filename' => $file->store('attachments', 'public'),
                    'filetype' => $file->getMimeType(),
                    'type' => $type,
                    'work_order_id' => $workOrder->id,
                    'user_id' => $vendor->user_id,
                    'is_publish_to_owner_portal' => true,
                    'is_publish_to_tenant_portal' => true,
                    'created_at' => now(),
                ]);

                UploadAttachment::dispatch($attachment);
            }

            return back()->with('success', 'Photos uploaded. They are syncing in the background.');
        } catch (\Throwable $e) {
            Log::error('Vendor portal attachment upload failed', [
                'work_order_id' => $workOrder->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Could not upload photos. Please try again.']);
        }
    }

    /**
     * Upload a vendor invoice (file + amount) and sync it to PropertyWare.
     *
     * The invoice is committed before the PropertyWare sync so a transient sync
     * failure can never lose the vendor's submission.
     */
    public function uploadInvoice(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'filename' => 'required|file|mimes:jpg,jpeg,png,pdf|max:51200',
        ]);

        try {
            $file = $request->file('filename');
            $extension = $file->getClientOriginalExtension();
            $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $validated['title']);
            $uniqueName = $safeName.'_'.uniqid().'.'.$extension;

            $invoice = Invoice::create([
                'title' => $validated['title'],
                'filename' => $file->storeAs('invoices', $uniqueName, 'public'),
                'filetype' => $file->getMimeType(),
                'amount' => $validated['amount'],
                'work_order_id' => $workOrder->id,
                'vendor_id' => $vendor->id,
                'is_publish_to_owner_portal' => false,
                'is_publish_to_tenant_portal' => false,
                'status' => 'approved',
            ]);
        } catch (\Throwable $e) {
            Log::error('Vendor portal invoice upload failed', [
                'work_order_id' => $workOrder->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['invoice' => 'Could not upload your invoice. Please try again.']);
        }

        // Turnover invoices are billed through Operation Accounting, so tell
        // them the moment one lands.
        if ($workOrder->isTurnover()) {
            NotifyOperationAccountingOfTurnoverInvoice::dispatch($invoice->id);
        }

        // Sync to PropertyWare only after the invoice is safely stored.
        try {
            (new PropertyWareService)->uploadVendorInvoice($invoice->work_order_id, $invoice);
        } catch (\Throwable $e) {
            Log::error('Vendor portal invoice PropertyWare sync failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('warning', 'Invoice uploaded. The PropertyWare sync will be retried by our team.');
        }

        return back()->with('success', 'Invoice uploaded successfully.');
    }

    /**
     * Create a service schedule for this vendor + work order, reusing the system's
     * create logic (which also updates the work order dates and PropertyWare sync).
     */
    public function storeSchedule(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');

        // The vendor is known from the token, so inject it instead of asking.
        // The flag tells store() this schedule came from the vendor, so the
        // owner is notified of the appointment.
        $request->merge([
            'vendor_id' => $vendor->id,
            'work_order_id' => $workOrder->id,
            'notify_owner_of_schedule' => true,
        ]);

        return app(ServiceScheduleController::class)->store($request);
    }

    /**
     * Record a message from the vendor to the work-order coordinator.
     *
     * The message is stored as an inbound "vendor" conversation (is_read = false)
     * so it appears in the coordinator's existing conversation view. No SMS is
     * dispatched — the coordinator reads it inside the system.
     */
    public function sendMessage(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');

        $validated = $request->validate([
            'text' => 'nullable|string|max:1600',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $hasImages = $request->hasFile('images');
        $messageText = trim($validated['text'] ?? '');

        if ($messageText === '' && ! $hasImages) {
            return back()->withErrors(['message' => 'Please type a message or attach an image.']);
        }

        $workOrder->loadMissing('woc.wocNumber.twilioPhoneNumber');

        $vendorNumber = $vendor->user?->phone ?: 'portal';
        $wocNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?? config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        DB::beginTransaction();

        try {
            $conversation = Conversation::create([
                'message' => $messageText,
                'sender_number' => $vendorNumber,
                'receiver_number' => $wocNumber,
                'work_order_id' => $workOrder->id,
                'vendor_id' => $vendor->id,
                'conversation_type' => 'vendor',
                'is_read' => false,
                'read_by_vendor' => true,
                'is_mms' => $hasImages,
            ]);

            if ($hasImages) {
                foreach ($request->file('images') as $image) {
                    $filename = time().'_'.$image->getClientOriginalName();
                    $imagePath = $image->storeAs('conversation_images', $filename);

                    ConversationMedia::create([
                        'message_id' => $conversation->id,
                        'original_url' => '',
                        'local_path' => $imagePath,
                        'content_type' => $image->getMimeType(),
                        'file_name' => $image->getClientOriginalName(),
                    ]);
                }
            }

            // Surface the message in the coordinator's notification feed, which is
            // driven by the activity log. Without this, WOC/admin are never notified.
            activity()
                ->performedOn($conversation)
                ->event('work_order_message_received')
                ->withProperties([
                    'senderNumber' => $vendorNumber,
                    'receiverNumber' => $wocNumber,
                    'message' => $messageText !== '' ? $messageText : '[image]',
                    'work_order_id' => $workOrder->id,
                    'vendor_id' => $vendor->id,
                    'source' => 'vendor_portal',
                ])
                ->log('Work Order #'.$workOrder->work_order_no.' - New Vendor Message');

            DB::commit();

            return back()->with('success', 'Message sent to your coordinator.');
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Vendor portal message failed', [
                'work_order_id' => $workOrder->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['message' => 'Could not send your message. Please try again.']);
        }
    }

    /**
     * Let the vendor mark one of their own assigned tasks complete, reusing the
     * system's task-update workflow (service-status advancement + PropertyWare).
     */
    public function completeTask(Request $request, string $token, WorkOrderTask $task)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');

        abort_unless(
            $task->work_order_id === $workOrder->id && $task->assigned_user_id === $vendor->user_id,
            403
        );

        $request->merge(['status' => 'completed']);

        try {
            // Reuse the exact same logic the in-app task checkbox uses.
            return app(TaskController::class)->update($request, $task);
        } catch (\Throwable $e) {
            Log::error('Vendor portal task completion sync failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('warning', 'Task marked complete. The status sync will be retried by our team.');
        }
    }

    /**
     * Mark this vendor's coordinator messages as seen (clears the portal badge).
     */
    public function markMessagesRead(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var Vendor $vendor */
        $vendor = $request->attributes->get('portal_vendor');

        Conversation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('conversation_type', 'vendor')
            ->where('read_by_vendor', false)
            ->forVendorThread($vendor)
            ->update(['read_by_vendor' => true]);

        return back();
    }

    /**
     * Urgency accent for a dashboard card, mirroring the in-app WorkOrderCard:
     * red = emergency / overdue, blue = due today, green = upcoming.
     *
     * @param  Collection<int, WorkOrderTask>  $ownTasks
     */
    private function cardAccent(WorkOrder $workOrder, $ownTasks): string
    {
        if ($workOrder->is_emergency) {
            return 'red';
        }

        $today = now()->toDateString();

        if ($workOrder->scheduled_end_date) {
            $scheduled = Carbon::parse($workOrder->scheduled_end_date)->toDateString();

            if ($scheduled === $today) {
                return 'blue';
            }

            return $scheduled < $today ? 'red' : 'green';
        }

        $pendingDueDates = $ownTasks
            ->where('status', 'pending')
            ->pluck('due_date')
            ->filter()
            ->map(fn ($date) => Carbon::parse($date)->toDateString());

        if ($pendingDueDates->contains(fn ($date) => $date < $today)) {
            return 'red';
        }

        if ($pendingDueDates->contains($today)) {
            return 'blue';
        }

        return 'green';
    }
}
