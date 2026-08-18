<?php

namespace App\Console\Commands;

use App\Services\MessageTriageService;
use Illuminate\Console\Command;

/**
 * Asks the AI what each thread's newest unanswered inbound message is about
 * (reschedule request, complaint, access issue, job done, question) and
 * records any concrete appointment proposal as a schedule suggestion. The
 * verdict storage and fail-open behaviour live in MessageTriageService; a
 * run with AI unavailable changes nothing.
 */
class TriageInboundMessages extends Command
{
    protected $signature = 'inbox:triage-messages';

    protected $description = 'Tag newest inbound messages with an intent and extract proposed appointment times as suggestions';

    public function handle(MessageTriageService $triage): int
    {
        $result = $triage->classify();

        $this->info(sprintf(
            'Judged %d message(s); %d schedule suggestion(s) recorded; %d still awaiting judgement.',
            $result['judged'],
            $result['suggested'],
            $result['pending'],
        ));

        return self::SUCCESS;
    }
}
