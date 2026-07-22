<?php

namespace App\Jobs;

use App\Models\Owner;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\OwnerWorkOrderEmailSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOwnerVendorAssignmentEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $workOrderId, public int $vendorId) {}

    public function handle(OwnerWorkOrderEmailSender $sender): void
    {
        $workOrder = WorkOrder::query()->with(['owners', 'requested_by', 'building'])->find($this->workOrderId);
        $vendor = Vendor::query()->find($this->vendorId);

        if ($workOrder === null || $vendor === null || $vendor->isOwnerPlaceholder()) {
            return;
        }

        $owner = $workOrder->primaryOwner();

        if (! $owner instanceof Owner || ! filter_var($owner->email, FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($owner->email), '@texasrenter.com')) {
            return;
        }

        $html = view('emails.owner-vendor-assignment', [
            'owner' => $owner,
            'vendor' => $vendor,
            'workOrder' => $workOrder,
            'propertyAddress' => trim((string) ($workOrder->requested_by?->address ?: $workOrder->building?->address ?: 'the property')),
            // Vacant units (WOC "Vacant" toggle or turnover) have no tenant for
            // the vendor to contact, so drop that line while still notifying the
            // owner that a vendor was assigned.
            'includeTenantLine' => ! $workOrder->isVacant(),
        ])->render();

        $sender->sendVendorAssignment(
            $workOrder,
            $owner,
            $vendor,
            'Vendor Assigned - Work Order #'.$workOrder->work_order_no,
            $html,
        );
    }
}
