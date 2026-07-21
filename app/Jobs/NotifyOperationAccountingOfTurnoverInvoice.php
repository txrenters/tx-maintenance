<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\MicrosoftGraphMailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * When an invoice is uploaded on a Turnover work order (vendor portal or staff
 * UI), email Operation Accounting the invoice file with the work order details
 * and a link to the work order.
 */
class NotifyOperationAccountingOfTurnoverInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    /**
     * Graph rejects large JSON attachments, so anything bigger is referenced
     * via the work order link instead of attached.
     */
    private const MAX_ATTACHMENT_BYTES = 3 * 1024 * 1024;

    public function __construct(public int $invoiceId) {}

    public function handle(MicrosoftGraphMailService $graph): void
    {
        if (! config('services.operation_accounting.turnover_invoice_notifications')) {
            return;
        }

        $to = (string) config('services.operation_accounting.email');
        $invoice = Invoice::withoutGlobalScopes()->find($this->invoiceId);

        if (blank($to) || ! $invoice) {
            return;
        }

        $workOrder = WorkOrder::withoutGlobalScopes()
            ->with(['building', 'service_status'])
            ->find($invoice->work_order_id);

        if (! $workOrder?->isTurnover()) {
            return;
        }

        $vendor = $invoice->vendor_id ? Vendor::find($invoice->vendor_id) : null;

        $attachments = [];
        $attached = false;

        if ($invoice->filename && Storage::disk('public')->exists($invoice->filename)) {
            $bytes = Storage::disk('public')->get($invoice->filename);

            if (strlen($bytes) <= self::MAX_ATTACHMENT_BYTES) {
                $attachments[] = [
                    'name' => basename($invoice->filename),
                    'contentType' => $invoice->filetype ?: 'application/octet-stream',
                    'contentBytes' => base64_encode($bytes),
                ];
                $attached = true;
            }
        }

        $html = view('emails.operation-accounting-invoice', [
            'workOrder' => $workOrder,
            'invoice' => $invoice,
            'vendor' => $vendor,
            'attached' => $attached,
            'workOrderUrl' => route('work_orders.details', $workOrder),
        ])->render();

        $graph->sendMail(
            to: $to,
            cc: [],
            subject: 'Turnover Invoice Uploaded - Work Order #'.$workOrder->work_order_no,
            html: $html,
            attachments: $attachments,
        );

        Log::info('Operation Accounting notified of turnover invoice.', [
            'invoice_id' => $invoice->id,
            'work_order_id' => $workOrder->id,
            'attached' => $attached,
        ]);
    }
}
