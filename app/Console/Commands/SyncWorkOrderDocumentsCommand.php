<?php

namespace App\Console\Commands;

use App\Mail\VendorServiceRequestMail;
use App\Models\Attachments;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocuments;
use App\Services\PropertyWareService;
use App\Services\WorkOrderEmailSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SyncWorkOrderDocumentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:work-order-documents {--limit=200 : How many of the newest work orders to sync documents for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync PropertyWare documents (and send pending vendor service requests) for the newest work orders, on its own schedule so the fast work order import is never blocked by per-work-order document API calls';

    /**
     * PropertyWare system/automation user whose files should never be imported.
     */
    private const SKIP_CREATED_BY_USER = 'a0e71e98';

    protected PropertyWareService $propertyWareService;

    /**
     * Memoized check for the service_request_sent_at column so we never email
     * vendors before the migration that tracks "already sent" has run.
     */
    private ?bool $canTrackServiceRequest = null;

    public function __construct(PropertyWareService $propertyWareService)
    {
        parent::__construct();
        $this->propertyWareService = $propertyWareService;
    }

    /**
     * Execute the console command.
     *
     * Mirrors the window the SOAP import covers (the newest work orders by
     * created date) so documents keep arriving for exactly the set the import
     * maintains — just decoupled from it.
     */
    public function handle(): void
    {
        $startedAt = microtime(true);
        $limit = max(1, (int) $this->option('limit'));

        $workOrders = WorkOrder::query()
            ->whereNotNull('propertyware_id')
            ->orderByDesc('created_date')
            ->limit($limit)
            ->get();

        Log::info('Work order document sync is running.', [
            'work_order_count' => $workOrders->count(),
        ]);

        $synced = 0;

        foreach ($workOrders as $workOrder) {
            $this->syncWorkOrderDocuments($workOrder->propertyware_id, $workOrder->id);

            // Vendor service-request emails now fire instantly on assignment in
            // WorkOrderController::vendor_change (generated PDF + upload to
            // PropertyWare) via SendVendorWorkOrderInformation. The old batch
            // email below is disabled to avoid a second, outdated email. Re-enable
            // it (and the sendServiceRequestToVendors method) if PropertyWare-side
            // (import) assignments should also be auto-emailed.
            // $this->sendServiceRequestToVendors($workOrder);

            $synced++;
        }

        $totalMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::info('Work order document sync finished.', [
            'total_ms' => $totalMs,
            'work_order_count' => $synced,
            'avg_ms_per_work_order' => $synced ? (int) round($totalMs / $synced) : 0,
        ]);
    }

    /**
     * Pull the documents PropertyWare has for a work order and store their
     * metadata locally, idempotently (keyed by the PropertyWare document id).
     * Documents created by our own PropertyWare API user are skipped so files
     * this app uploaded to PropertyWare are not re-imported as duplicates, and
     * PropertyWare's auto-generated THMP_ thumbnails are never pulled.
     */
    private function syncWorkOrderDocuments($propertywareWorkOrderId, int $workOrderId): void
    {
        if (! $propertywareWorkOrderId) {
            return;
        }

        try {
            $documents = $this->propertyWareService->getWorkOrderDocuments($propertywareWorkOrderId);
            $ourPropertywareUser = config('services.propertyware.username');

            foreach ($documents as $document) {
                $doc = (array) $document;

                if (empty($doc['id'])) {
                    continue;
                }

                // Skip what this app uploaded to PropertyWare to avoid redundancy.
                if ($ourPropertywareUser && ! empty($doc['createdBy']) && $doc['createdBy'] === $ourPropertywareUser) {
                    continue;
                }

                // Skip files created by the PropertyWare system/automation user.
                if (! empty($doc['createdBy']) && $doc['createdBy'] === self::SKIP_CREATED_BY_USER) {
                    continue;
                }

                $fileName = $doc['fileName'] ?? null;

                // Skip PropertyWare's auto-generated thumbnails (THMP_ previews).
                if (WorkOrderDocuments::isThumbnailFileName($fileName)) {
                    continue;
                }

                // Skip our own uploads (matched by name) and PropertyWare's repeated
                // system files — same file name under a different document id.
                if ($fileName) {
                    $duplicateName = WorkOrderDocuments::where('work_order_id', $workOrderId)
                        ->where('file_name', $fileName)
                        ->where('propertyware_id', '!=', $doc['id'])
                        ->exists()
                        || Attachments::withoutGlobalScopes()
                            ->where('work_order_id', $workOrderId)
                            ->where('pw_file_name', $fileName)
                            ->exists();

                    if ($duplicateName) {
                        continue;
                    }
                }

                WorkOrderDocuments::updateOrCreate(
                    [
                        'propertyware_id' => $doc['id'],
                        'work_order_id' => $workOrderId,
                    ],
                    [
                        'created_by_id' => $doc['createdBy'] ?? null,
                        'description' => $doc['description'] ?? null,
                        'file_name' => $doc['fileName'] ?? null,
                        'file_type' => $doc['fileType'] ?? null,
                        'is_publish_to_owner_portal' => $doc['publishToOwnerPortal'] ?? false,
                        'is_publish_to_tenant_portal' => $doc['publishToTenantPortal'] ?? false,
                        'system_id' => env('PROPERTYWARE_SYSTEM_ID'),
                    ]
                );
            }
        } catch (\Throwable $th) {
            Log::error('Work order document sync failed', [
                'work_order_id' => $workOrderId,
                'work_order_pw_id' => $propertywareWorkOrderId,
                'error' => $th->getMessage(),
            ]);
        }
    }

    /**
     * If the work order has a "Work Order Information.pdf" document and a vendor
     * with an email is assigned, email that PDF to the vendor(s) with the
     * standard service-request message — exactly once per work order.
     */
    private function sendServiceRequestToVendors(WorkOrder $workOrder): void
    {
        try {
            // Without the tracking column we cannot record that we've sent, which
            // would make every sync run re-email. Do nothing until the migration
            // has run (deploy-order safe).
            $this->canTrackServiceRequest ??= Schema::hasColumn('work_orders', 'service_request_sent_at');
            if (! $this->canTrackServiceRequest) {
                return;
            }

            // Already sent for this work order.
            if ($workOrder->service_request_sent_at) {
                return;
            }

            $document = WorkOrderDocuments::where('work_order_id', $workOrder->id)
                ->where('file_name', 'Work Order Information.pdf')
                ->first();

            // No service request document yet — nothing to send.
            if (! $document) {
                return;
            }

            $vendors = $workOrder->vendors()->get()->filter(fn ($vendor) => filled($vendor->email));

            // No vendor with an email assigned yet — try again on a later run.
            if ($vendors->isEmpty()) {
                return;
            }

            $pdf = $this->propertyWareService->downloadDocument($document->propertyware_id);

            // Couldn't fetch the file — leave unsent so a later run retries.
            if (! $pdf) {
                return;
            }

            foreach ($vendors as $vendor) {
                // Each vendor's own magic-link to this work order in the portal,
                // where they can upload photos. Null token => no button shown.
                $portalUrl = filled($vendor->pivot->access_token ?? null)
                    ? route('vendor.portal.show', $vendor->pivot->access_token)
                    : null;

                // The Blade design is unchanged — render the existing mailable to
                // HTML and hand it to the sender as trusted template HTML (no
                // sanitize), which persists it as an outbound EmailMessage.
                $mailable = new VendorServiceRequestMail(
                    vendorName: $vendor->name ?? 'Vendor',
                    workOrderNo: (string) $workOrder->work_order_no,
                    pdfContent: $pdf['content'],
                    portalUrl: $portalUrl,
                );

                app(WorkOrderEmailSender::class)->sendVendorEmail(
                    workOrder: $workOrder,
                    vendor: $vendor,
                    subject: 'New Service Request - Work Order #'.$workOrder->work_order_no,
                    html: $mailable->render(),
                    files: [[
                        'name' => $mailable->pdfFileName,
                        'contentType' => 'application/pdf',
                        'bytes' => $pdf['content'],
                    ]],
                    trustedHtml: true,
                    // Turnover jobs are coordinated by the THMP coordinator, so
                    // their vendor emails go out from that mailbox instead of
                    // the shared work-orders one.
                    mailbox: $workOrder->isTurnover()
                        ? (string) config('services.microsoft.turnover_mailbox')
                        : null,
                );
            }

            $workOrder->forceFill(['service_request_sent_at' => now()])->save();

            Log::info('Service request emailed to vendor(s)', [
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'vendor_count' => $vendors->count(),
            ]);
        } catch (\Throwable $th) {
            Log::error('Failed to email service request to vendors', [
                'work_order_id' => $workOrder->id,
                'error' => $th->getMessage(),
            ]);
        }
    }
}
