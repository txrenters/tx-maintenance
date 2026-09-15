<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\InvoiceNumberExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Fills in the vendor's invoice number from the uploaded file when whoever
 * uploaded it left the box blank. Operation Accounting files every bill under
 * that number, and until now it lived only inside the PDF.
 *
 * Runs after the upload has already succeeded, so a slow or failed read never
 * holds up or loses an invoice. It only ever fills a blank: a number the
 * office or the vendor typed is never overwritten.
 */
class ExtractInvoiceNumber implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [60];

    public function __construct(public int $invoiceId) {}

    public function handle(InvoiceNumberExtractor $extractor): void
    {
        $invoice = Invoice::withoutGlobalScopes()->find($this->invoiceId);

        if (! $invoice || filled($invoice->invoice_number)) {
            return;
        }

        if (! Storage::disk('public')->exists($invoice->filename)) {
            Log::warning('Invoice number read skipped: file missing.', ['invoice_id' => $invoice->id]);

            return;
        }

        $number = $extractor->extract(
            (string) Storage::disk('public')->get($invoice->filename),
            (string) $invoice->filetype
        );

        if ($number === null) {
            return;
        }

        // Someone may have typed a number while this sat in the queue; theirs
        // wins over what was read from the file.
        $invoice->refresh();

        if (filled($invoice->invoice_number)) {
            return;
        }

        $invoice->forceFill(['invoice_number' => $number])->save();

        activity('invoice')
            ->performedOn($invoice)
            ->withProperties([
                'invoice_number' => $number,
                'work_order_id' => $invoice->work_order_id,
            ])
            ->log('invoice number read from the file');
    }
}
