<?php

namespace App\Http\Controllers;

use App\Jobs\SendVendorWorkOrderInformation;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Models\WorkOrderVendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The WOC's manual "send assignment info" button on the vendor conversation
 * tab. The automated dispatch only fires when a vendor is newly attached
 * through the app's own assign flow, so vendors assigned in PropertyWare — or
 * whose contact info was only fixed after the automation ran — never hear
 * about the job. This re-arms the one-shot claim and queues the same
 * email + text the automation would have sent.
 */
class VendorAssignmentNotificationController extends Controller
{
    public function store(WorkOrder $workOrder, Vendor $vendor): JsonResponse
    {
        $pivot = DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->first();

        if (! $pivot) {
            return response()->json(['error' => 'This vendor is not assigned to this work order.'], 422);
        }

        if ($workOrder->automationPausedFor('vendor')) {
            return response()->json(['error' => 'Vendor automation is paused on this work order. Turn it back on to send.'], 422);
        }

        if (blank($vendor->email) && blank($vendor->phone)) {
            return response()->json(['error' => 'This vendor has no email or phone number on file — add one on the vendor record first.'], 422);
        }

        $updates = ['information_sent_at' => null];

        // Assignments imported from PropertyWare never got a portal token;
        // mint one so the message can carry the vendor's magic link.
        if (blank($pivot->access_token)) {
            $updates['access_token'] = WorkOrderVendor::generateUniqueAccessToken();
        }

        DB::table('work_order_vendors')
            ->where('work_order_id', $workOrder->id)
            ->where('vendor_id', $vendor->id)
            ->update($updates);

        SendVendorWorkOrderInformation::dispatch($workOrder->id, $vendor->id);

        return response()->json([
            'queued' => true,
            'email' => filled($vendor->email),
            'text' => filled($vendor->phone),
        ]);
    }
}
