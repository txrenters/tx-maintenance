<?php

namespace App\Services;

use App\Models\WorkOrder;
use Barryvdh\DomPDF\Facade\Pdf;

class WorkOrderInformationPdf
{
    /**
     * File name used for the generated document, both in the email attachment
     * and in PropertyWare. Kept stable so it reads consistently everywhere.
     */
    public const FILE_NAME = 'Work Order Information.pdf';

    /**
     * Render the Work Order Information sheet for a work order to raw PDF bytes.
     */
    public function render(WorkOrder $workOrder): string
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
        ])->output();
    }
}
