<?php

namespace App\Console\Commands;

use App\Services\CourtesyCloserService;
use Illuminate\Console\Command;

/**
 * Asks the AI which newest inbound messages are courtesy closers ("thank
 * you", "ok great") so the awaiting-reply badge, Inbox, board summary and
 * unanswered report stop holding those threads open. The verdict cache and
 * the fail-open behaviour live in CourtesyCloserService; a run with AI
 * unavailable changes nothing.
 */
class ClassifyCourtesyClosers extends Command
{
    protected $signature = 'inbox:classify-courtesy';

    protected $description = 'Judge which newest inbound messages are courtesy closers so awaiting-reply counts skip them';

    public function handle(CourtesyCloserService $courtesyClosers): int
    {
        $result = $courtesyClosers->classify();

        $this->info(sprintf(
            'Judged %d message(s); %d thread(s) currently closed by courtesy; %d still awaiting judgement.',
            $result['judged'],
            $result['courtesy'],
            $result['pending'],
        ));

        return self::SUCCESS;
    }
}
