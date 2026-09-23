<?php

namespace App\Console\Commands;

use App\Services\InvoiceMailboxAutoReplyService;
use Illuminate\Console\Command;

/**
 * Polls the invoices mailbox and answers vendors who emailed an invoice with a
 * pointer to their portal dashboard.
 *
 * Run with --dry-run to list what the poller would do without sending,
 * recording or moving the cursor. That is also how mailbox access is proven
 * on production before the feature is switched on.
 */
class AutoReplyInvoiceEmails extends Command
{
    protected $signature = 'invoices:auto-reply
        {--dry-run : List the decisions without sending or recording anything}';

    protected $description = 'Reply to vendors who email invoices to the invoices mailbox with their portal link';

    public function handle(InvoiceMailboxAutoReplyService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! config('services.invoices_mailbox.auto_reply_enabled')) {
            $this->warn('Invoices mailbox auto-reply is off (INVOICES_MAILBOX_AUTO_REPLY_ENABLED). Nothing to do.');

            return self::SUCCESS;
        }

        $results = $service->run($dryRun);

        if ($results === []) {
            $this->info('No new messages in the invoices mailbox.');

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            $this->line(sprintf('%s  %s  "%s"', str_pad($result['outcome'], 20), $result['from'], $result['subject']));
        }

        $replied = count(array_filter($results, fn (array $r) => str_starts_with($r['outcome'], 'replied')));
        $this->info(sprintf('%s %d of %d message(s).', $dryRun ? 'Would reply to' : 'Replied to', $replied, count($results)));

        return self::SUCCESS;
    }
}
