<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillInvoiceNumbers;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkOrder;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class InvoiceNumberBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    private WorkOrder $workOrder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Sleep::fake();

        $this->vendor = Vendor::query()->create([
            'propertyware_id' => 'V-9001',
            'name' => 'Professionals Same Day Repair',
            'is_active' => true,
            'user_id' => User::factory()->create()->id,
        ]);

        $this->workOrder = WorkOrder::factory()->create([
            'work_order_no' => 43795,
            'status' => 'Open',
        ]);
    }

    /**
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

    private function jobberPdf(string $number): string
    {
        return $this->pdfWithLines([
            'Texas Home Maintenance Pros',
            '123 Main St, Austin TX',
            "Invoice #{$number}",
            'Date: Sep 15, 2026',
            'Total: $250.00',
        ]);
    }

    private function scannedPdf(): string
    {
        $pdf = new FPDF;
        $pdf->AddPage();

        return $pdf->Output('S');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeInvoice(string $filename, string $contents, array $overrides = []): Invoice
    {
        Storage::disk('public')->put($filename, $contents);

        return Invoice::query()->create(array_merge([
            'title' => 'Labor and parts',
            'invoice_number' => null,
            'filename' => $filename,
            'filetype' => 'application/pdf',
            'amount' => 250.00,
            'status' => 'approved',
            'work_order_id' => $this->workOrder->id,
            'vendor_id' => $this->vendor->id,
            'created_at' => Carbon::now()->subDays(10),
        ], $overrides));
    }

    private function number(Invoice $invoice): ?string
    {
        return Invoice::withoutGlobalScopes()->findOrFail($invoice->id)->invoice_number;
    }

    public function test_a_dry_run_reads_every_file_and_saves_nothing(): void
    {
        $first = $this->makeInvoice('invoices/a.pdf', $this->jobberPdf('5087'));
        $second = $this->makeInvoice('invoices/b.pdf', $this->jobberPdf('5088'));

        // The harness matches one expected substring per printed line.
        $this->artisan('invoices:backfill-numbers', ['--dry-run' => true, '--sleep' => 0])
            ->expectsOutputToContain('2 file(s) will be read (dry run, nothing will be saved)')
            ->expectsOutputToContain('would set 5087')
            ->expectsOutputToContain('would set 5088')
            ->assertSuccessful();

        $this->assertNull($this->number($first));
        $this->assertNull($this->number($second));
        $this->assertDatabaseMissing('activity_log', ['description' => BackfillInvoiceNumbers::LOG_DESCRIPTION]);
    }

    public function test_it_fills_only_recent_blank_invoices(): void
    {
        $recent = $this->makeInvoice('invoices/recent.pdf', $this->jobberPdf('5087'));
        $old = $this->makeInvoice('invoices/old.pdf', $this->jobberPdf('4001'), [
            'created_at' => Carbon::now()->subMonths(4),
        ]);
        $typed = $this->makeInvoice('invoices/typed.pdf', $this->jobberPdf('9999'), [
            'invoice_number' => 'TYPED-1',
        ]);
        $archived = $this->makeInvoice('invoices/archived.pdf', $this->jobberPdf('7000'), [
            'archived_at' => Carbon::now()->subDay(),
        ]);
        $unlabelled = $this->makeInvoice('invoices/unlabelled.pdf', $this->pdfWithLines([
            'Professionals Same Day Repair',
            'Thank you for your business',
            'Total due $638.00',
        ]));

        $this->artisan('invoices:backfill-numbers', ['--sleep' => 0])
            ->expectsOutputToContain('2 invoice(s) uploaded since')
            ->expectsOutputToContain('set 5087')
            ->expectsOutputToContain('no number found')
            ->assertSuccessful();

        $this->assertSame('5087', $this->number($recent));
        $this->assertNull($this->number($old));
        $this->assertSame('TYPED-1', $this->number($typed));
        $this->assertNull($this->number($archived));
        $this->assertNull($this->number($unlabelled));

        $this->assertDatabaseHas('activity_log', [
            'description' => BackfillInvoiceNumbers::LOG_DESCRIPTION,
            'subject_id' => $recent->id,
        ]);
        $this->assertSame(1, \DB::table('activity_log')->where('description', BackfillInvoiceNumbers::LOG_DESCRIPTION)->count());
    }

    public function test_the_window_follows_the_months_option(): void
    {
        $sixWeeksOld = $this->makeInvoice('invoices/six-weeks.pdf', $this->jobberPdf('5087'), [
            'created_at' => Carbon::now()->subWeeks(6),
        ]);

        $this->artisan('invoices:backfill-numbers', ['--months' => 1, '--sleep' => 0])
            ->expectsOutputToContain('0 invoice(s) uploaded since')
            ->assertSuccessful();

        $this->assertNull($this->number($sixWeeksOld));

        $this->artisan('invoices:backfill-numbers', ['--months' => 2, '--sleep' => 0])
            ->expectsOutputToContain('set 5087')
            ->assertSuccessful();

        $this->assertSame('5087', $this->number($sixWeeksOld));
    }

    public function test_scans_stay_blank_and_cost_nothing_unless_vision_is_asked_for(): void
    {
        config([
            'services.invoices.number_vision_enabled' => true,
            'services.invoices.number_vision_model' => 'gpt-5-mini',
            'ai.providers.openai.key' => 'test-key',
        ]);

        // Faked up front so a call made with vision off would be recorded and fail the run.
        Http::fake([
            '*/responses' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode(['invoice_number' => '6624']),
                    ]],
                ]],
            ], 200),
        ]);

        $scan = $this->makeInvoice('invoices/scan.pdf', $this->scannedPdf());

        $this->artisan('invoices:backfill-numbers', ['--sleep' => 0])
            ->expectsOutputToContain('Vision: off')
            ->expectsOutputToContain('no number found')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($this->number($scan));

        $this->artisan('invoices:backfill-numbers', ['--sleep' => 0, '--vision' => true])
            ->expectsOutputToContain('Vision: ON')
            ->expectsOutputToContain('set 6624')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame('6624', $this->number($scan));
    }

    public function test_the_limit_stops_after_that_many_files(): void
    {
        $first = $this->makeInvoice('invoices/a.pdf', $this->jobberPdf('5087'));
        $second = $this->makeInvoice('invoices/b.pdf', $this->jobberPdf('5088'));

        $this->artisan('invoices:backfill-numbers', ['--limit' => 1, '--sleep' => 0])
            ->expectsOutputToContain(sprintf(
                '2 invoice(s) uploaded since %s have no invoice number; 1 file(s) will be read',
                Carbon::now()->subMonths(3)->toDateString()
            ))
            ->expectsOutputToContain('set 5087')
            ->assertSuccessful();

        $this->assertSame('5087', $this->number($first));
        $this->assertNull($this->number($second));
    }

    public function test_missing_and_oversized_files_are_skipped_without_stopping_the_run(): void
    {
        $missing = $this->makeInvoice('invoices/missing.pdf', $this->jobberPdf('1'));
        Storage::disk('public')->delete('invoices/missing.pdf');

        $big = $this->makeInvoice('invoices/big.pdf', $this->jobberPdf('2').str_repeat(' ', 4096));
        $fine = $this->makeInvoice('invoices/fine.pdf', $this->jobberPdf('5087'));

        $this->artisan('invoices:backfill-numbers', ['--sleep' => 0, '--max-mb' => '0.003'])
            ->expectsOutputToContain('skipped: file missing')
            ->expectsOutputToContain('over the size cap')
            ->expectsOutputToContain('set 5087')
            ->assertSuccessful();

        $this->assertNull($this->number($missing));
        $this->assertNull($this->number($big));
        $this->assertSame('5087', $this->number($fine));
    }

    public function test_rerunning_finds_nothing_left_to_do(): void
    {
        $invoice = $this->makeInvoice('invoices/a.pdf', $this->jobberPdf('5087'));

        $this->artisan('invoices:backfill-numbers', ['--sleep' => 0])->assertSuccessful();
        $this->artisan('invoices:backfill-numbers', ['--sleep' => 0])
            ->expectsOutputToContain('0 invoice(s) uploaded since')
            ->assertSuccessful();

        $this->assertSame('5087', $this->number($invoice));
        $this->assertSame(1, \DB::table('activity_log')->where('description', BackfillInvoiceNumbers::LOG_DESCRIPTION)->count());
    }

    public function test_undo_clears_what_it_wrote_and_keeps_what_the_office_changed(): void
    {
        $untouched = $this->makeInvoice('invoices/a.pdf', $this->jobberPdf('5087'));
        $corrected = $this->makeInvoice('invoices/b.pdf', $this->jobberPdf('5088'));
        $typed = $this->makeInvoice('invoices/c.pdf', $this->jobberPdf('9999'), ['invoice_number' => 'TYPED-1']);

        $this->artisan('invoices:backfill-numbers', ['--sleep' => 0])->assertSuccessful();
        $this->assertSame('5088', $this->number($corrected));

        Invoice::withoutGlobalScopes()->whereKey($corrected->id)->update(['invoice_number' => '5088-A']);

        $this->artisan('invoices:backfill-numbers', ['--undo' => true, '--dry-run' => true])
            ->expectsOutputToContain('2 backfill entries found (dry run')
            ->expectsOutputToContain('would clear 5087')
            ->expectsOutputToContain('kept: now 5088-A')
            ->assertSuccessful();

        $this->assertSame('5087', $this->number($untouched));

        $this->artisan('invoices:backfill-numbers', ['--undo' => true])
            ->expectsOutputToContain('cleared 5087')
            ->expectsOutputToContain('kept: now 5088-A')
            ->assertSuccessful();

        $this->assertNull($this->number($untouched));
        $this->assertSame('5088-A', $this->number($corrected));
        $this->assertSame('TYPED-1', $this->number($typed));
        $this->assertDatabaseHas('activity_log', [
            'description' => BackfillInvoiceNumbers::UNDO_DESCRIPTION,
            'subject_id' => $untouched->id,
        ]);

        // A second undo has nothing left to clear.
        $this->artisan('invoices:backfill-numbers', ['--undo' => true])
            ->expectsOutputToContain('kept: now 5088-A')
            ->assertSuccessful();

        $this->assertSame(1, \DB::table('activity_log')->where('description', BackfillInvoiceNumbers::UNDO_DESCRIPTION)->count());
    }
}
