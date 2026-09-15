<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceNumberExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;

/**
 * Fills in the vendor's invoice number, read out of the uploaded file, on the
 * recent invoices where nobody typed one. New uploads get this automatically
 * (see ExtractInvoiceNumber); this catches up the ones uploaded before that
 * shipped, a few months back and no further.
 *
 * Built to be run by hand over SSH on the live server while it is serving:
 * one file at a time, a rest between files, a size cap, nothing sent to
 * OpenAI unless asked, a dry run that does the full read and shows every
 * number it would set, and an undo that clears exactly what it filled in.
 * Only ever fills a blank; a number the office or the vendor typed is never
 * touched.
 */
class BackfillInvoiceNumbers extends Command
{
    public const LOG_DESCRIPTION = 'invoice number backfilled from the file';

    public const UNDO_DESCRIPTION = 'invoice number backfill undone';

    protected $signature = 'invoices:backfill-numbers
        {--months=3 : Only invoices uploaded within this many months}
        {--dry-run : Read every file and show what would be filled in, changing nothing}
        {--limit= : Stop after this many files}
        {--sleep=250 : Milliseconds to rest between files so the web site keeps its share of the server}
        {--max-mb=10 : Skip any file larger than this many megabytes}
        {--vision : Also send photos and scanned PDFs to OpenAI (costs money; off by default)}
        {--undo : Clear every number this command filled in that nobody has changed since}';

    protected $description = 'Fill in the vendor invoice number from the uploaded file on recent invoices where nobody typed one.';

    private int $filled = 0;

    private int $noNumber = 0;

    private int $skipped = 0;

    private float $slowestMs = 0;

    private string $slowestFile = '';

    public function handle(InvoiceNumberExtractor $extractor): int
    {
        if ($this->option('undo')) {
            return $this->undo();
        }

        $months = max(1, (int) $this->option('months'));
        $since = Carbon::now()->subMonths($months)->startOfDay();
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $dryRun = (bool) $this->option('dry-run');
        $maxBytes = (int) round(((float) $this->option('max-mb')) * 1024 * 1024);

        // Photos and scans go to OpenAI only when asked for on this run.
        config(['services.invoices.number_vision_enabled' => (bool) $this->option('vision')]);

        $query = Invoice::withoutGlobalScopes()
            ->with('work_order:id,work_order_no')
            ->whereNull('invoice_number')
            ->whereNull('archived_at')
            ->where('created_at', '>=', $since)
            ->orderBy('id');

        $total = $query->count();
        $planned = $limit !== null ? min($limit, $total) : $total;

        $this->info(sprintf(
            '%s invoice(s) uploaded since %s have no invoice number; %s file(s) will be read%s.',
            $total,
            $since->toDateString(),
            $planned,
            $dryRun ? ' (dry run, nothing will be saved)' : ''
        ));
        $this->line(sprintf(
            'Vision: %s. Size cap: %s MB. Rest between files: %d ms. PHP memory_limit: %s.',
            $this->option('vision') ? 'ON (photos and scans go to OpenAI)' : 'off',
            $this->option('max-mb'),
            (int) $this->option('sleep'),
            ini_get('memory_limit')
        ));

        if ($planned === 0) {
            return self::SUCCESS;
        }

        $startedAt = microtime(true);
        $done = 0;

        foreach ($query->lazyById(50) as $invoice) {
            if ($done > 0) {
                Sleep::usleep(max(0, (int) $this->option('sleep')) * 1000);
            }

            $this->processInvoice($invoice, $extractor, $dryRun, $maxBytes);
            $done++;

            gc_collect_cycles();

            if ($limit !== null && $done >= $limit) {
                break;
            }
        }

        $this->newLine();
        $this->table(['Result', 'Count'], [
            [$dryRun ? 'Would fill in' : 'Filled in', $this->filled],
            ['No number found in the file', $this->noNumber],
            ['Skipped (missing, too large)', $this->skipped],
        ]);
        $this->line(sprintf(
            'Took %.1fs for %d file(s). Peak memory %.1f MB. Slowest file %.0f ms (%s).',
            microtime(true) - $startedAt,
            $done,
            memory_get_peak_usage(true) / 1024 / 1024,
            $this->slowestMs,
            $this->slowestFile
        ));

        if ($dryRun && $this->filled > 0) {
            $this->info('Dry run: nothing was saved. Run again without --dry-run to fill these in.');
        }

        return self::SUCCESS;
    }

    private function processInvoice(Invoice $invoice, InvoiceNumberExtractor $extractor, bool $dryRun, int $maxBytes): void
    {
        $label = sprintf('#%d  WO %s  %s', $invoice->id, $invoice->work_order?->work_order_no ?? '?', $invoice->filename);
        $disk = Storage::disk('public');

        if (! $disk->exists($invoice->filename)) {
            $this->skipped++;
            $this->warn("{$label}  skipped: file missing");

            return;
        }

        $size = $disk->size($invoice->filename);

        if ($size > $maxBytes) {
            $this->skipped++;
            $this->warn(sprintf('%s  skipped: %.1f MB is over the size cap', $label, $size / 1024 / 1024));

            return;
        }

        $started = hrtime(true);

        $contents = (string) $disk->get($invoice->filename);
        $number = $extractor->extract($contents, (string) $invoice->filetype);
        unset($contents);

        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        if ($elapsedMs > $this->slowestMs) {
            $this->slowestMs = $elapsedMs;
            $this->slowestFile = $invoice->filename;
        }

        if ($number === null) {
            $this->noNumber++;
            $this->line("{$label}  no number found");

            return;
        }

        $this->filled++;

        if ($dryRun) {
            $this->line("{$label}  would set {$number}");

            return;
        }

        // The office may have typed one while this was running; theirs wins.
        $invoice->refresh();

        if (filled($invoice->invoice_number)) {
            $this->filled--;
            $this->line("{$label}  left alone: {$invoice->invoice_number} was typed meanwhile");

            return;
        }

        $invoice->forceFill(['invoice_number' => $number])->save();

        activity('invoice')
            ->performedOn($invoice)
            ->withProperties([
                'invoice_number' => $number,
                'work_order_id' => $invoice->work_order_id,
            ])
            ->log(self::LOG_DESCRIPTION);

        $this->line("{$label}  set {$number}");
    }

    /**
     * Put back the blanks this command filled in, but only where the number is
     * still the one it wrote: anything the office corrected since is kept.
     */
    private function undo(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $entries = Activity::query()
            ->where('description', self::LOG_DESCRIPTION)
            ->where('subject_type', (new Invoice)->getMorphClass())
            ->orderBy('id')
            ->get();

        $this->info(sprintf('%d backfill entr%s found%s.', $entries->count(), $entries->count() === 1 ? 'y' : 'ies', $dryRun ? ' (dry run, nothing will be changed)' : ''));

        $cleared = 0;
        $kept = 0;

        foreach ($entries as $entry) {
            // Same rest as the fill, so 270 quick writes in a row do not
            // crowd out the pages the site is serving.
            if ($cleared > 0 && ! $dryRun) {
                Sleep::usleep(max(0, (int) $this->option('sleep')) * 1000);
            }

            $invoice = Invoice::withoutGlobalScopes()->find($entry->subject_id);
            $written = (string) data_get($entry->properties, 'invoice_number');

            if (! $invoice || blank($invoice->invoice_number)) {
                continue;
            }

            $label = sprintf('#%d  %s', $invoice->id, $invoice->filename);

            if ((string) $invoice->invoice_number !== $written) {
                $kept++;
                $this->line("{$label}  kept: now {$invoice->invoice_number}, changed since the backfill wrote {$written}");

                continue;
            }

            $cleared++;

            if ($dryRun) {
                $this->line("{$label}  would clear {$written}");

                continue;
            }

            $invoice->forceFill(['invoice_number' => null])->save();

            activity('invoice')
                ->performedOn($invoice)
                ->withProperties([
                    'invoice_number' => $written,
                    'work_order_id' => $invoice->work_order_id,
                ])
                ->log(self::UNDO_DESCRIPTION);

            $this->line("{$label}  cleared {$written}");
        }

        $this->newLine();
        $this->table(['Result', 'Count'], [
            [$dryRun ? 'Would clear' : 'Cleared', $cleared],
            ['Kept (changed by hand since)', $kept],
        ]);

        return self::SUCCESS;
    }
}
