<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendWorkOrderEmailRequest;
use App\Models\EmailAttachment;
use App\Models\OwnerEmailNotification;
use App\Models\TenantEmailNotification;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\WorkOrderEmailSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkOrderEmailController extends Controller
{
    public function __construct(private WorkOrderEmailSender $sender) {}

    public function index(WorkOrder $workOrder, Request $request): JsonResponse
    {
        Gate::authorize('view_vendors', Vendor::class);

        $vendorId = $request->integer('vendor_id');

        abort_unless(
            $vendorId === 0 || $workOrder->vendors()->whereKey($vendorId)->exists(),
            404,
        );

        $emails = $workOrder->emailMessages()
            ->when($vendorId !== 0, fn ($query) => $query->where('vendor_id', $vendorId))
            ->with('attachments')
            ->latest('emailed_at')
            ->latest('id')
            ->get();

        $vendor = $vendorId !== 0 ? $workOrder->vendors()->find($vendorId) : null;

        return response()->json([
            'vendor_emails' => $emails,
            'sender_email' => (string) $request->user()->email,
            'recipient_email' => $vendor?->email,
        ]);
    }

    public function notifications(WorkOrder $workOrder): JsonResponse
    {
        Gate::authorize('view_vendors', Vendor::class);

        $vendorEmails = $workOrder->emailMessages()
            ->with('vendor:id,name')
            ->get()
            ->map(fn ($email): array => $this->notificationData($email, 'Vendor', $email->vendor?->name));

        $ownerEmails = OwnerEmailNotification::query()
            ->where('work_order_id', $workOrder->id)
            ->with('owner:id,name,first_name,last_name')
            ->get()
            ->map(fn ($email): array => $this->notificationData($email, 'Owner', $email->owner?->name));

        $tenantEmails = TenantEmailNotification::query()
            ->where('work_order_id', $workOrder->id)
            ->with('tenant:id,first_name,last_name')
            ->get()
            ->map(fn ($email): array => $this->notificationData(
                $email,
                'Tenant',
                trim(($email->tenant?->first_name ?? '').' '.($email->tenant?->last_name ?? '')),
                'Tenant contact history',
            ));

        return response()->json([
            'emails' => $vendorEmails
                ->concat($ownerEmails)
                ->concat($tenantEmails)
                ->sortByDesc(fn (array $email): string => $email['sent_at'].'-'.$email['id'])
                ->values(),
        ]);
    }

    public function store(SendWorkOrderEmailRequest $request, WorkOrder $workOrder): RedirectResponse
    {
        $data = $request->validated();
        $vendor = $workOrder->vendors()->findOrFail($data['vendor_id']);

        $this->sender->sendVendorEmail(
            workOrder: $workOrder,
            vendor: $vendor,
            subject: $data['subject'],
            html: $data['body'],
            files: $request->file('attachments', []),
            sentBy: $request->user(),
            cc: [],
            to: $data['to'],
            mailbox: $data['from_email'],
        );

        return back();
    }

    public function vendorStore(SendWorkOrderEmailRequest $request, Vendor $vendor): RedirectResponse
    {
        $data = $request->validated();

        $this->sender->sendVendorEmail(
            workOrder: null,
            vendor: $vendor,
            subject: $data['subject'],
            html: $data['body'],
            files: $request->file('attachments', []),
            sentBy: $request->user(),
            cc: [],
            to: $data['to'],
            mailbox: $data['from_email'],
        );

        return back();
    }

    public function download(EmailAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view_vendors', Vendor::class);

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }

    private function notificationData(object $email, string $recipientType, ?string $recipientName, ?string $scope = null): array
    {
        return [
            'id' => $recipientType.'-'.$email->id,
            'recipient_type' => $recipientType,
            'recipient_name' => $recipientName ?: $recipientType,
            'scope' => $scope,
            'direction' => $email->direction,
            'subject' => $email->subject,
            'body_html' => $email->body_html,
            'body_text' => $email->body_text,
            'from_email' => $email->from_email,
            'to_email' => $email->to_email,
            'sent_at' => ($email->sent_at ?? $email->emailed_at)?->toIso8601String(),
        ];
    }
}
