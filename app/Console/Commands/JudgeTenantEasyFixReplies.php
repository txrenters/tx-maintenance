<?php

namespace App\Console\Commands;

use App\Services\TenantEasyFixReplyJudge;
use Illuminate\Console\Command;

/**
 * The sweep behind the "Ready to close" label on the Tenant Easy Fix board:
 * every open easy-fix work order whose newest tenant reply has not been
 * read yet gets one AI reading. Runs every ten minutes. Read-only on the
 * work order — it labels, it never closes or moves anything.
 *
 * --work-order takes the LOCAL id.
 */
class JudgeTenantEasyFixReplies extends Command
{
    protected $signature = 'easy-fix:judge-replies
        {--work-order= : One work order id, instead of every open one}
        {--limit=200 : How many work orders to check in a run}';

    protected $description = 'Read each new tenant reply on the Tenant Easy Fix board and label the card "Ready to close" when the tenant says the fix worked.';

    public function handle(TenantEasyFixReplyJudge $judge): int
    {
        if (! $judge->enabled()) {
            $this->warn('The easy-fix reply judge is off (TENANT_EASY_FIX_READY_JUDGE_ENABLED) or no AI provider is configured. Nothing to do.');

            return self::SUCCESS;
        }

        $one = $this->option('work-order');
        $candidates = $judge->candidates(filled($one) ? (int) $one : null, (int) $this->option('limit'));

        if ($candidates->isEmpty()) {
            $this->info('Nothing to judge: no unread tenant replies on the easy-fix board.');

            return self::SUCCESS;
        }

        $ready = 0;
        $read = 0;

        foreach ($candidates as $candidate) {
            $workOrder = $candidate['work_order'];
            $label = '#'.($workOrder->work_order_no ?? $workOrder->id);
            $insight = $judge->judge($workOrder, $candidate['reply']);

            if ($insight === null) {
                $this->line("{$label}: AI unavailable, left for the next run.");

                continue;
            }

            $read++;
            $reading = TenantEasyFixReplyJudge::summarize($insight);

            if ($reading['ready']) {
                $ready++;
                $this->line("{$label}: ready to close ({$reading['confidence']}%) — {$reading['reason']}");
            } else {
                $this->line("{$label}: not ready ({$reading['confidence']}%) — {$reading['reason']}");
            }
        }

        $this->info("{$read} reply(ies) read, {$ready} ready to close.");

        return self::SUCCESS;
    }
}
