<?php

namespace Tests\Feature;

use App\Jobs\ExtractInvoiceNumber;
use App\Models\Attachments;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use App\Services\InvoiceNumberExtractor;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceNumberExtractionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real PDF with a text layer, one line per entry, as an invoicing app
     * or a browser's print-to-PDF would produce.
     *
     * @param  array<int, string>  $lines
     */
    private function pdfWithLines(array $lines): string
    {
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);

        foreach ($lines as $line) {
            $pdf->Cell(0, 8, $line, 0, 1);
        }

        return $pdf->Output('S');
    }

    /** A PDF with no text at all, which is what a scanned page looks like to the parser. */
    private function scannedPdf(): string
    {
        $pdf = new FPDF;
        $pdf->AddPage();

        return $pdf->Output('S');
    }

    private function fakeVisionAnswer(?string $number): void
    {
        Http::fake([
            '*/responses' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode(['invoice_number' => $number]),
                    ]],
                ]],
            ], 200),
        ]);
    }

    private function visionOn(): void
    {
        config([
            'services.invoices.number_vision_enabled' => true,
            'services.invoices.number_vision_model' => 'gpt-5-mini',
            'ai.providers.openai.key' => 'test-key',
        ]);
    }

    /**
     * @return array{0: Vendor, 1: WorkOrder}
     */
    private function makeVendorAndWorkOrder(): array
    {
        $vendor = Vendor::query()->create([
            'propertyware_id' => 'V-9001',
            'name' => 'Professionals Same Day Repair',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $workOrder = WorkOrder::factory()->create([
            'work_order_no' => 43795,
            'status' => 'Open',
        ]);

        $workOrder->vendors()->attach($vendor->id, ['access_token' => 'token-psdr']);

        // The portal refuses an invoice from a vendor who has uploaded no
        // photos, so give them one: this suite is about the number, not the gate.
        Attachments::query()->create([
            'title' => 'Vendor photo',
            'filename' => 'attachments/proof.jpg',
            'filetype' => 'image/jpeg',
            'type' => 'after',
            'work_order_id' => $workOrder->id,
            'user_id' => $vendor->user_id,
        ]);

        return [$vendor, $workOrder];
    }

    private function makeInvoice(WorkOrder $workOrder, Vendor $vendor, string $filename, ?string $number = null): Invoice
    {
        return Invoice::query()->create([
            'title' => 'Labor and parts',
            'invoice_number' => $number,
            'filename' => $filename,
            'filetype' => 'application/pdf',
            'amount' => 638.00,
            'status' => 'approved',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
        ]);
    }

    public function test_reads_the_number_beside_its_label(): void
    {
        $extractor = new InvoiceNumberExtractor;

        // Jobber's layout: label and number together.
        $this->assertSame('5087', $extractor->fromText("Texas Home Maintenance Pros\nInvoice #5087\nDate: Sep 15, 2026"));
        $this->assertSame('00123', $extractor->fromText('Invoice No. 00123   Date 09/15/2026   Total $250.00'));
        $this->assertSame('A-2024-118', $extractor->fromText('Invoice Number: A-2024-118'));
        $this->assertSame('7731', $extractor->fromText('Invoice: #7731'));
    }

    public function test_reads_the_number_from_a_column_layout(): void
    {
        // Professionals Same Day Repair (WO 43795): the labels sit in one column
        // and the values in the next, so the text layer lists the labels first.
        $text = implode("\n", [
            'INVOICE',
            'Professionals Same Day Repair',
            '4212 Woodhead St #1046 Houston TX 77098',
            '(346) 787-1295',
            'Invoice #',
            'Date',
            'Balance',
            'Due On',
            '6624',
            'Tue Sep 15, 2026',
            '$638.00',
            'Thu Oct 15, 2026',
            'Bill To:',
        ]);

        $this->assertSame('6624', (new InvoiceNumberExtractor)->fromText($text));
    }

    public function test_reads_the_number_from_a_label_row_over_a_value_row(): void
    {
        $text = "Invoice #        Date            Amount\n6624             09/15/2026      \$638.00";

        $this->assertSame('6624', (new InvoiceNumberExtractor)->fromText($text));
    }

    public function test_a_heading_a_date_an_amount_and_a_phone_are_not_the_number(): void
    {
        $text = implode("\n", [
            'INVOICE',
            '4212 Woodhead St #1046',
            'Invoice Date: 09/15/2026',
            'Total $638.00',
            'Phone 346-787-1295',
        ]);

        $this->assertNull((new InvoiceNumberExtractor)->fromText($text), 'Without a labelled number there is nothing safe to take.');
    }

    public function test_reads_an_inv_style_reference_without_a_label(): void
    {
        $this->assertSame('INV-2024-118', (new InvoiceNumberExtractor)->fromText('Ref INV-2024-118 for job 43795, due on receipt.'));
    }

    public function test_reads_the_text_layer_of_a_real_pdf_for_free(): void
    {
        Http::fake();
        $this->visionOn();

        $pdf = $this->pdfWithLines([
            'Professionals Same Day Repair',
            'Invoice # 6624',
            'Date Tue Sep 15, 2026',
            'Balance $638.00',
        ]);

        $this->assertSame('6624', (new InvoiceNumberExtractor)->extract($pdf, 'application/pdf'));
        Http::assertNothingSent();
    }

    public function test_a_text_pdf_without_a_labelled_number_stays_blank_and_never_reaches_the_model(): void
    {
        Http::fake();
        $this->visionOn();

        $pdf = $this->pdfWithLines([
            'Professionals Same Day Repair',
            'Statement of work performed at 8 Centennial Ridge Pl',
            'Total $638.00',
            'Thank you for your business.',
        ]);

        $this->assertNull((new InvoiceNumberExtractor)->extract($pdf, 'application/pdf'));
        Http::assertNothingSent();
    }

    public function test_a_scanned_pdf_is_read_by_the_vision_model(): void
    {
        $this->visionOn();
        $this->fakeVisionAnswer('6624');

        $this->assertSame('6624', (new InvoiceNumberExtractor)->extract($this->scannedPdf(), 'application/pdf'));

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/responses')
                && $request['model'] === 'gpt-5-mini'
                && data_get($request->data(), 'input.0.content.1.type') === 'input_file';
        });
    }

    public function test_a_photo_is_sent_at_high_detail_and_a_null_answer_stays_blank(): void
    {
        $this->visionOn();
        $this->fakeVisionAnswer(null);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $this->assertNull((new InvoiceNumberExtractor)->extract($png, 'image/png'));

        // "high" caps the image tokens; the newer models default to "original".
        Http::assertSent(function ($request) {
            return data_get($request->data(), 'input.0.content.1.type') === 'input_image'
                && data_get($request->data(), 'input.0.content.1.detail') === 'high';
        });
    }

    public function test_the_vision_read_is_off_when_disabled_or_without_a_key(): void
    {
        Http::fake();

        config(['services.invoices.number_vision_enabled' => false, 'ai.providers.openai.key' => 'test-key']);
        $this->assertNull((new InvoiceNumberExtractor)->extract($this->scannedPdf(), 'application/pdf'));

        config(['services.invoices.number_vision_enabled' => true, 'ai.providers.openai.key' => '']);
        $this->assertNull((new InvoiceNumberExtractor)->extract($this->scannedPdf(), 'application/pdf'));

        Http::assertNothingSent();
    }

    public function test_the_job_fills_a_blank_number_from_the_file(): void
    {
        Storage::fake('public');
        [$vendor, $workOrder] = $this->makeVendorAndWorkOrder();

        Storage::disk('public')->put('invoices/read-me.pdf', $this->pdfWithLines([
            'Texas Home Maintenance Pros',
            '5225 Katy Freeway, Ste 545, Houston, TX 77007',
            'Invoice #5087',
            'Amount $250.00',
        ]));
        $invoice = $this->makeInvoice($workOrder, $vendor, 'invoices/read-me.pdf');

        (new ExtractInvoiceNumber($invoice->id))->handle(app(InvoiceNumberExtractor::class));

        $this->assertSame('5087', Invoice::withoutGlobalScopes()->find($invoice->id)->invoice_number);
    }

    public function test_the_job_never_overwrites_a_typed_number_and_survives_a_missing_file(): void
    {
        Storage::fake('public');
        [$vendor, $workOrder] = $this->makeVendorAndWorkOrder();

        Storage::disk('public')->put('invoices/typed.pdf', $this->pdfWithLines([
            'Texas Home Maintenance Pros',
            '5225 Katy Freeway, Ste 545, Houston, TX 77007',
            'Invoice #5087',
        ]));
        $typed = $this->makeInvoice($workOrder, $vendor, 'invoices/typed.pdf', 'TYPED-1');
        $missing = $this->makeInvoice($workOrder, $vendor, 'invoices/gone.pdf');

        (new ExtractInvoiceNumber($typed->id))->handle(app(InvoiceNumberExtractor::class));
        (new ExtractInvoiceNumber($missing->id))->handle(app(InvoiceNumberExtractor::class));

        $this->assertSame('TYPED-1', Invoice::withoutGlobalScopes()->find($typed->id)->invoice_number, 'A typed number wins over the file.');
        $this->assertNull(Invoice::withoutGlobalScopes()->find($missing->id)->invoice_number, 'A missing file is logged and skipped.');
    }

    public function test_a_portal_upload_without_a_number_queues_the_read_and_a_typed_one_does_not(): void
    {
        Storage::fake('public');
        Http::fake();
        Queue::fake();

        $this->makeVendorAndWorkOrder();

        $this->post(route('vendor.portal.invoice', 'token-psdr'), [
            'title' => 'Labor and parts',
            'amount' => '638.00',
            'filename' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $blank = Invoice::withoutGlobalScopes()->latest('id')->first();
        Queue::assertPushed(ExtractInvoiceNumber::class, fn (ExtractInvoiceNumber $job) => $job->invoiceId === $blank->id);

        $this->post(route('vendor.portal.invoice', 'token-psdr'), [
            'title' => 'Labor and parts',
            'invoice_number' => '6624',
            'amount' => '638.00',
            'filename' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $typed = Invoice::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame('6624', $typed->invoice_number);
        Queue::assertNotPushed(ExtractInvoiceNumber::class, fn (ExtractInvoiceNumber $job) => $job->invoiceId === $typed->id);
    }

    public function test_a_staff_upload_without_a_number_queues_the_read_too(): void
    {
        Storage::fake('public');
        Http::fake();
        Queue::fake();

        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        [$vendor, $workOrder] = $this->makeVendorAndWorkOrder();

        $this->actingAs($admin)->post(route('api.invoices.store'), [
            'title' => 'Labor and parts',
            'amount' => '638.00',
            'work_order_id' => $workOrder->id,
            'vendor_id' => $vendor->id,
            'is_publish_to_owner_portal' => 'No',
            'is_publish_to_tenant_portal' => 'No',
            'filename' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $invoice = Invoice::withoutGlobalScopes()->latest('id')->first();
        Queue::assertPushed(ExtractInvoiceNumber::class, fn (ExtractInvoiceNumber $job) => $job->invoiceId === $invoice->id);
    }
}
