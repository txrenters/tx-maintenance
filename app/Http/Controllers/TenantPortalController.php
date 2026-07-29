<?php

namespace App\Http\Controllers;

use App\Jobs\UploadAttachment;
use App\Models\Attachments;
use App\Models\TenantUploadToken;
use App\Models\WorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public, no-login tenant portal — the tenant-facing counterpart of the vendor
 * portal. A magic-link token (resolved by the tenant.portal middleware) scopes
 * everything to one work order; the tenant can view the request, upload photos
 * of the issue, and mark themselves done.
 */
class TenantPortalController extends Controller
{
    public function show(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var TenantUploadToken $uploadToken */
        $uploadToken = $request->attributes->get('portal_upload_token');

        $workOrder->load('service_status');

        $tenant = $workOrder->requested_by;

        // Only photos uploaded through the tenant portal for this work order —
        // a tenant must never see internal/vendor files.
        $attachments = Attachments::query()
            ->withoutGlobalScopes()
            ->where('work_order_id', $workOrder->id)
            ->where('uploaded_via_tenant_portal', true)
            ->latest()
            ->get(['id', 'title', 'filename', 'filetype', 'created_at']);

        $isHoa = $uploadToken->purpose === TenantUploadToken::PURPOSE_HOA_VIOLATION;

        return inertia('TenantPortal/Show', [
            'title' => 'Service Request #'.$workOrder->work_order_no,
            'token' => $uploadToken->token,
            'tenantName' => trim((string) ($tenant?->first_name ?? '')),
            'completed' => $uploadToken->isCompleted(),
            'isHoa' => $isHoa,
            'deadline' => $isHoa
                ? $uploadToken->hoa_deadline_at?->timezone('America/Chicago')->format('l, F j, Y')
                : null,
            'workOrder' => [
                'work_order_no' => $workOrder->work_order_no,
                'description' => $workOrder->description,
                'status' => $workOrder->service_status?->name ?? $workOrder->status,
                'address' => $workOrder->propertyAddress() ?? '',
                'created_date' => $workOrder->created_date,
            ],
            'attachments' => $attachments->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'url' => asset('storage/'.$a->filename),
                'is_image' => str_starts_with((string) $a->filetype, 'image/')
                    || (bool) preg_match('/\.(jpe?g|png|gif|webp)$/i', (string) $a->filename),
                'created_at' => $a->created_at,
            ])->values(),
        ]);
    }

    /**
     * Upload one or more photos of the issue and sync them to PropertyWare.
     * Mirrors the vendor portal upload; a successful upload also counts as
     * completing the request, which stops the reminder texts.
     */
    public function uploadAttachments(Request $request)
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $request->attributes->get('portal_work_order');
        /** @var TenantUploadToken $uploadToken */
        $uploadToken = $request->attributes->get('portal_upload_token');

        $validated = $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|mimes:jpg,jpeg,png,gif,webp,pdf|max:51200',
        ]);

        try {
            foreach ($request->file('files') as $file) {
                $attachment = Attachments::create([
                    'title' => 'Tenant photo - WO#'.$workOrder->work_order_no,
                    'filename' => $file->store('attachments', 'public'),
                    'filetype' => $file->getMimeType(),
                    'type' => 'before',
                    'work_order_id' => $workOrder->id,
                    'user_id' => $workOrder->requested_by?->user_id,
                    'uploaded_via_tenant_portal' => true,
                    'is_publish_to_owner_portal' => true,
                    'is_publish_to_tenant_portal' => true,
                    'created_at' => now(),
                ]);

                UploadAttachment::dispatch($attachment);
            }

            // Photos received: stop the reminders for this request.
            if (! $uploadToken->isCompleted()) {
                $uploadToken->update(['completed_at' => now()]);
            }

            return back()->with('success', 'Photos uploaded — thank you!');
        } catch (\Throwable $e) {
            Log::error('Tenant portal attachment upload failed', [
                'work_order_id' => $workOrder->id,
                'tenant_upload_token_id' => $uploadToken->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Could not upload photos. Please try again.']);
        }
    }

    /**
     * The tenant says they are done (photos sent or issue resolved) — stop the
     * reminders. The link stays usable in case they want to add more photos.
     */
    public function complete(Request $request)
    {
        /** @var TenantUploadToken $uploadToken */
        $uploadToken = $request->attributes->get('portal_upload_token');

        if (! $uploadToken->isCompleted()) {
            $uploadToken->update(['completed_at' => now()]);
        }

        return back()->with('success', 'Thank you! Our team has been notified.');
    }
}
