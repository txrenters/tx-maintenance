<?php

namespace App\Jobs;

use App\Mail\VendorServiceRequestMail;
use App\Models\Conversation;
use App\Models\Owner;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderDocuments;
use App\Services\PropertyWareService;
use App\Services\WorkOrderInformationPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * When a vendor is newly assigned to a work order: generate the Work Order
 * Information PDF, email it to the vendor, upload it to PropertyWare, and text
 * the vendor a short notification that is also saved into the WOC↔Vendor
 * conversation thread (sent as the coordinator/WOC).
 */
class SendVendorWorkOrderInformation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $workOrderId,
        public int $vendorId,
    ) {}

    public function handle(WorkOrderInformationPdf $pdfService, PropertyWareService $propertyWare): void
    {
        $workOrder = WorkOrder::with(['woc.wocNumber.twilioPhoneNumber', 'owners', 'building'])->find($this->workOrderId);
        $vendor = Vendor::find($this->vendorId);

        if (! $workOrder || ! $vendor) {
            return;
        }

        // Atomically claim this notification. A double dispatch, or a retry after
        // a worker timeout, would otherwise re-run every step below and the vendor
        // would get a second email/text (and a duplicate PropertyWare doc). The
        // conditional UPDATE only affects a row that hasn't been notified yet, so
        // exactly one run proceeds; any other sees zero rows and bows out.
        $claimed = DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->whereNull('information_sent_at')
            ->update(['information_sent_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $fileName = WorkOrderInformationPdf::FILE_NAME;

        // Scope the sheet to this recipient so co-assigned vendors aren't
        // disclosed to one another.
        $pdf = $pdfService->render($workOrder, $vendor);

        $accessToken = $workOrder->vendors()
            ->where('vendors.id', $vendor->id)
            ->first()?->pivot?->access_token;

        $portalUrl = $accessToken ? route('vendor.portal.show', $accessToken) : null;

        // 1) Email the vendor (only when we have an address to send to).
        if (filled($vendor->email)) {
            Mail::to($vendor->email)->send(new VendorServiceRequestMail(
                vendorName: $vendor->name,
                workOrderNo: (string) $workOrder->work_order_no,
                pdfContent: $pdf,
                portalUrl: $portalUrl,
                pdfFileName: $fileName,
            ));
        }

        // 2) Upload the generated PDF back to PropertyWare, then record it locally
        //    so it shows in the work order's Attachments tab (downloadable — the
        //    bytes stream from PropertyWare by this document id). The scheduled
        //    sync skips re-importing it because it was created by our PW API user.
        $docId = $propertyWare->uploadWorkOrderPdf(
            $workOrder->propertyware_id,
            $pdf,
            $fileName,
            'Work Order Information',
        );

        if ($docId) {
            WorkOrderDocuments::updateOrCreate(
                [
                    'propertyware_id' => $docId,
                    'work_order_id' => $workOrder->id,
                ],
                [
                    'file_name' => $fileName,
                    'file_type' => 'application/pdf',
                    'description' => 'Work Order Information',
                    'created_by_id' => config('services.propertyware.username'),
                    'system_id' => env('PROPERTYWARE_SYSTEM_ID'),
                ],
            );
        }

        // 3) Text the vendor and log it in the WOC↔Vendor conversation.
        $this->textVendor($workOrder, $vendor);

        // 4) Notify the property owner and log it in the owner conversation thread.
        $this->notifyOwner($workOrder, $vendor);
    }

    /**
     * Text the primary property owner that a vendor has been assigned, sent from
     * (and recorded as) the WOC, and persist it to the owner conversation thread
     * so it shows in the coordinator's owner tab.
     */
    private function notifyOwner(WorkOrder $workOrder, Vendor $vendor): void
    {
        $owner = $workOrder->primaryOwner();

        // No owner on file — nothing to notify.
        if (! $owner) {
            return;
        }

        $ownerNumber = $this->toE164($owner->phone);

        // No usable phone number for the owner — skip (like the vendor path).
        if (! $ownerNumber) {
            return;
        }

        $wocNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        if (! $wocNumber) {
            return;
        }

        $body = $this->buildOwnerMessage($workOrder, $vendor, $owner);

        // sender_number is the WOC's number, so the portal renders this as a
        // message from the coordinator on the owner thread.
        $conversation = Conversation::create([
            'message' => $body,
            'sender_number' => $wocNumber,
            'receiver_number' => $ownerNumber,
            'work_order_id' => $workOrder->id,
            'conversation_type' => 'owner',
            'is_read' => true,
            'is_mms' => false,
        ]);

        SendConversationMessageJob::dispatch($ownerNumber, $wocNumber, $body, null, $conversation->id);
    }

    private function buildOwnerMessage(WorkOrder $workOrder, Vendor $vendor, Owner $owner): string
    {
        $ownerName = trim((string) $owner->name) ?: trim($owner->first_name.' '.$owner->last_name);

        $lines = [
            'Hi '.$ownerName.',',
            'We have assigned '.$this->vendorContactDetails($vendor).' to handle the repairs at '
                .$this->propertyAddress($workOrder).' under Work Order #'.$workOrder->work_order_no.'.',
            'The vendor will contact the tenant directly to coordinate and schedule the appointment. Thank you.',
        ];

        return implode("\n\n", $lines);
    }

    /**
     * The vendor's name followed by their phone number when we have one.
     */
    private function vendorContactDetails(Vendor $vendor): string
    {
        if (filled($vendor->phone)) {
            return $vendor->name.' ('.$vendor->phone.')';
        }

        return $vendor->name;
    }

    /**
     * A human-readable property address from the work order's building, or a
     * neutral fallback when the building has not been synced yet.
     */
    private function propertyAddress(WorkOrder $workOrder): string
    {
        $building = $workOrder->building;

        if (! $building) {
            return 'the property';
        }

        $parts = array_filter([
            trim((string) $building->address),
            trim((string) $building->city),
            trim(($building->state_region ?? '').' '.($building->postal_code ?? '')),
        ]);

        return $parts === [] ? 'the property' : implode(', ', $parts);
    }

    /**
     * Send the assignment SMS to the vendor, sent from (and recorded as) the
     * WOC, and persist it to the vendor conversation thread so it shows up in
     * the coordinator and vendor-portal views.
     */
    private function textVendor(WorkOrder $workOrder, Vendor $vendor): void
    {
        $vendorNumber = $this->toE164($vendor->phone);

        // Nothing to text — skip (the email already went out).
        if (! $vendorNumber) {
            return;
        }

        $wocNumber = $workOrder->woc?->wocNumber?->twilioPhoneNumber?->phone_number
            ?: config('services.twilio.maintenance_number', env('MAINTENANC_TWILIO_PHONE_NUMBER', ''));

        if (! $wocNumber) {
            return;
        }

        $body = $this->buildSmsBody($workOrder, $vendor);

        // sender_number is the WOC's number (not the vendor's), so the portal
        // renders this as a message from the coordinator.
        $conversation = Conversation::create([
            'message' => $body,
            'sender_number' => $wocNumber,
            'receiver_number' => $vendorNumber,
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'conversation_type' => 'vendor',
            'is_read' => true,
            'read_by_vendor' => false,
            'is_mms' => false,
        ]);

        SendConversationMessageJob::dispatch($vendorNumber, $wocNumber, $body, null, $conversation->id);
    }

    private function buildSmsBody(WorkOrder $workOrder, Vendor $vendor): string
    {
        $lines = [
            'Hello '.$vendor->name.',',
            'You have been assigned Work Order #'.$workOrder->work_order_no.'.',
        ];

        if ($workOrder->priority) {
            $lines[] = 'Priority: '.strtoupper($workOrder->priority);
        }

        if ($workOrder->description) {
            $lines[] = Str::limit($workOrder->description, 160);
        }

        $lines[] = '— TX Maintenance Team';

        return implode("\n", $lines);
    }

    /**
     * Normalize a US phone number to E.164 (+1XXXXXXXXXX); null if unusable.
     */
    private function toE164(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return $digits ? '+'.$digits : null;
    }
}
