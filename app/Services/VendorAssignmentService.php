<?php

namespace App\Services;

use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderVendor;
use App\Notifications\NewWorkOrderAssignNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VendorAssignmentService
{
    public function __construct(private PropertyWareService $propertyWare) {}

    /**
     * Assign a single vendor to a work order automatically, mirroring the manual
     * WorkOrderController::vendor_change flow for one vendor: generate the
     * magic-link access token, notify the vendor by email, and sync the vendor
     * list to PropertyWare. An activity-log entry records that it was automatic.
     *
     * Callers MUST gate this behind config so it never runs in local/testing —
     * it emails a real vendor and writes to PropertyWare.
     */
    public function autoAssign(WorkOrder $workOrder, Vendor $vendor, string $reason): void
    {
        $token = WorkOrderVendor::generateUniqueAccessToken();

        // syncWithoutDetaching leaves any existing vendors in place and adds this
        // one with its own token (a no-op on the token if already attached).
        $workOrder->vendors()->syncWithoutDetaching([
            $vendor->id => ['access_token' => $token],
        ]);

        // An auto-assigned vendor arrives after the status change that generates
        // checklist tasks; without them their portal cannot advance the status.
        TaskService::backfillVendorTasks($workOrder, [$vendor->id]);

        if (filled($vendor->email)) {
            try {
                $vendor->notify(new NewWorkOrderAssignNotification(
                    $workOrder,
                    route('vendor.portal.show', $token),
                ));

                AutomatedMessageLogService::log(
                    AutomatedMessageLogService::CHANNEL_EMAIL,
                    'vendor',
                    'vendor_assignment_notification_email',
                    $vendor->email,
                    $workOrder,
                    extra: ['vendor_id' => $vendor->id],
                );
            } catch (\Throwable $e) {
                Log::error('Auto-assign vendor notification failed.', [
                    'vendor_id' => $vendor->id,
                    'work_order_id' => $workOrder->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $vendorIDsXml = '<vendorIDs xsi:type="soapenc:Array" '
            .'xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">'
            .'<vendorID xsi:type="xsd:long">'.$vendor->propertyware_id.'</vendorID>'
            .'</vendorIDs>';

        $this->propertyWare->changeWorkOrderVendors($workOrder, $vendorIDsXml);

        $workOrder->update(['local_status' => 'Updated']);

        activity()
            ->performedOn($workOrder)
            ->event('vendor_auto_assigned')
            ->withProperties([
                'work_order_id' => $workOrder->id,
                'work_order_no' => $workOrder->work_order_no,
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
                'reason' => Str::limit($reason, 180),
                'read' => false,
            ])
            ->log('Vendor auto-assigned to Work Order #'.$workOrder->work_order_no);
    }
}
