<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignJobberJobVendorsRequest;
use App\Jobs\SendJobberVendorAssignment;
use App\Models\Jobber;
use App\Models\JobberJobVendor;
use Illuminate\Support\Facades\DB;

class JobberVendorController extends Controller
{
    /**
     * Assign vendors to a Jobber job.
     *
     * Mirrors WorkOrderController::vendor_change(), minus everything that
     * depends on a work order: there is no PropertyWare record to push to, and
     * no owner or tenant to notify. Only newly attached vendors are notified, so
     * re-saving an unchanged list sends nothing.
     */
    public function update(AssignJobberJobVendorsRequest $request, Jobber $job)
    {
        abort_unless(config('services.jobber.vendor_assign_enabled'), 404);

        $vendorIds = collect($request->validated('vendor_ids'))->map('intval')->unique()->values()->all();

        $attached = DB::transaction(function () use ($job, $vendorIds) {
            $changes = $job->vendors()->sync($vendorIds);

            foreach ($changes['attached'] as $vendorId) {
                $job->vendors()->updateExistingPivot($vendorId, [
                    'access_token' => JobberJobVendor::generateUniqueAccessToken(),
                ]);
            }

            return $changes['attached'];
        });

        foreach ($attached as $vendorId) {
            SendJobberVendorAssignment::dispatch($job->id, (int) $vendorId);
        }

        return redirect()->back()->with('success', 'Vendors updated successfully!');
    }
}
