<?php

namespace App\Services;

use App\Models\Vendor;
use App\Models\WorkOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

class WorkOrderInformationPdf
{
    /**
     * File name used for the generated document, both in the email attachment
     * and in PropertyWare. Kept stable so it reads consistently everywhere.
     */
    public const FILE_NAME = 'Work Order Information.pdf';

    /**
     * Render the Work Order Information sheet for a work order to raw PDF bytes.
     *
     * When a recipient vendor is given, the Vendors section is scoped to just
     * that vendor so co-assigned vendors are never disclosed to one another.
     */
    public function render(WorkOrder $workOrder, ?Vendor $recipientVendor = null): string
    {
        $workOrder->loadMissing([
            'managed_by',
            'woc.wocNumber.twilioPhoneNumber',
            'requested_by',
            'building',
            'vendors.user',
        ]);

        return Pdf::loadView('pdf.work-order-information', [
            'workOrder' => $workOrder,
            'vendors' => $this->vendorsToShow($workOrder, $recipientVendor),
        ])->output();
    }

    /**
     * The vendors to list on the sheet: only the recipient when one is given
     * (so vendors can't see who else is assigned), otherwise all of them.
     *
     * @return Collection<int, Vendor>
     */
    public function vendorsToShow(WorkOrder $workOrder, ?Vendor $recipientVendor = null): Collection
    {
        $workOrder->loadMissing('vendors.user');

        if (! $recipientVendor) {
            return $workOrder->vendors;
        }

        return $workOrder->vendors
            ->where('id', $recipientVendor->id)
            ->values();
    }
}
