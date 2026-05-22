<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\JobberTextMessage;
use App\Models\TwilioPhoneNumber;
use App\Services\InboundTwilioMessageProcessor;
use App\Services\TwilioService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ImportTwilioInboundMessages extends Command
{
    protected $signature = 'twilio:import-inbound-messages
                            {--lookback=60 : Minutes to look back when --since is not provided}
                            {--since= : ISO8601 datetime; overrides --lookback}
                            {--limit=200 : Max messages to pull per Twilio number per run}
                            {--dry-run : Scan and report without writing anything}';

    protected $description = 'Backfill inbound Twilio messages missed by the live webhook (e.g. when TwiML routing drops).';

    public function __construct(
        private readonly TwilioService $twilio,
        private readonly InboundTwilioMessageProcessor $processor
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $runId = (string) Str::uuid();
        $startedAt = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $since = $this->resolveSince();

        $numbers = TwilioPhoneNumber::query()
            ->pluck('phone_number')
            ->filter()
            ->unique()
            ->values();

        Log::info('twilio:import-inbound-messages run started', [
            'run_id' => $runId,
            'dry_run' => $dryRun,
            'lookback_minutes' => (int) $this->option('lookback'),
            'since' => $since->toIso8601String(),
            'limit_per_number' => $limit,
            'managed_numbers' => $numbers->all(),
        ]);

        if ($numbers->isEmpty()) {
            $this->warn('No Twilio phone numbers configured in twilio_phone_numbers table. Run twilio:sync-phone-numbers first.');
            Log::warning('twilio:import-inbound-messages aborted: no managed numbers', ['run_id' => $runId]);

            return self::SUCCESS;
        }

        $totals = [
            'scanned' => 0,
            'duplicate' => 0,
            'mirror' => 0,
            'missing_fields' => 0,
            'work_order' => 0,
            'jobber' => 0,
            'unmatched' => 0,
            'skipped_outbound' => 0,
            'errors' => 0,
        ];

        $this->info(sprintf(
            'Importing inbound Twilio messages sent after %s for %d number(s)%s',
            $since->format('Y-m-d H:i:s P'),
            $numbers->count(),
            $dryRun ? ' [dry-run]' : ''
        ));

        foreach ($numbers as $toNumber) {
            $this->line("  - {$toNumber}");

            $numberStats = ['scanned' => 0, 'imported' => 0, 'duplicates' => 0, 'errors' => 0];

            try {
                $messages = $this->twilio->streamInboundMessagesTo($toNumber, $since, $limit);
            } catch (\Throwable $e) {
                $this->error("    Failed to pull from Twilio for {$toNumber}: ".$e->getMessage());
                $totals['errors']++;
                Log::error('twilio:import-inbound-messages pull failed', [
                    'run_id' => $runId,
                    'to_number' => $toNumber,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($messages as $message) {
                $totals['scanned']++;
                $numberStats['scanned']++;

                if ($message->direction !== 'inbound') {
                    $totals['skipped_outbound']++;

                    continue;
                }

                $sid = (string) $message->sid;

                if ($sid !== '' && $this->alreadyStored($sid)) {
                    $totals['duplicate']++;
                    $numberStats['duplicates']++;

                    continue;
                }

                if ($this->alreadyStoredFuzzy($message)) {
                    $totals['duplicate']++;
                    $numberStats['duplicates']++;
                    Log::info('twilio:import-inbound-messages fuzzy duplicate skipped', [
                        'run_id' => $runId,
                        'sid' => $sid,
                        'from' => $message->from,
                        'to' => $message->to,
                    ]);

                    continue;
                }

                if ($dryRun) {
                    $this->line("    [dry-run] would import {$sid} from {$message->from} to {$message->to}");
                    Log::info('twilio:import-inbound-messages would import [dry-run]', [
                        'run_id' => $runId,
                        'sid' => $sid,
                        'from' => $message->from,
                        'to' => $message->to,
                    ]);

                    continue;
                }

                $payload = $this->buildPayload($message);

                try {
                    $result = $this->processor->process($payload);
                    $totals[$result] = ($totals[$result] ?? 0) + 1;
                    if (in_array($result, ['work_order', 'jobber'], true)) {
                        $numberStats['imported']++;
                    }
                    Log::info('twilio:import-inbound-messages message processed', [
                        'run_id' => $runId,
                        'sid' => $sid,
                        'from' => $message->from,
                        'to' => $message->to,
                        'result' => $result,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('twilio:import-inbound-messages processor exception', [
                        'run_id' => $runId,
                        'sid' => $sid,
                        'error' => $e->getMessage(),
                    ]);
                    $totals['errors']++;
                    $numberStats['errors']++;
                }
            }

            Log::info('twilio:import-inbound-messages number completed', [
                'run_id' => $runId,
                'to_number' => $toNumber,
            ] + $numberStats);
        }

        $this->newLine();
        $this->table(['metric', 'count'], collect($totals)->map(fn ($v, $k) => [$k, $v])->values()->all());

        Log::info('twilio:import-inbound-messages run completed', $totals + [
            'run_id' => $runId,
            'dry_run' => $dryRun,
            'since' => $since->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return self::SUCCESS;
    }

    private function resolveSince(): CarbonImmutable
    {
        $sinceOption = (string) $this->option('since');
        if ($sinceOption !== '') {
            return CarbonImmutable::parse($sinceOption);
        }

        $lookback = max(1, (int) $this->option('lookback'));

        return CarbonImmutable::now()->subMinutes($lookback);
    }

    private function alreadyStored(string $sid): bool
    {
        if (Conversation::where('twilio_sid', $sid)->exists()) {
            return true;
        }

        if (Schema::hasColumn('jobber_text_messages', 'twilio_sid')) {
            if (JobberTextMessage::where('twilio_sid', $sid)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fuzzy dedup safety net: matches on (sender, receiver, body) when an existing row's
     * created_at is within ±10 minutes of Twilio's date_sent. Catches pre-existing rows
     * that the live webhook stored without a twilio_sid.
     */
    private function alreadyStoredFuzzy($message): bool
    {
        $from = (string) ($message->from ?? '');
        $to = (string) ($message->to ?? '');
        $body = (string) ($message->body ?? '');
        $dateSent = $message->dateSent instanceof \DateTimeInterface
            ? CarbonImmutable::instance($message->dateSent)
            : null;

        if ($from === '' || $to === '' || ! $dateSent) {
            return false;
        }

        $windowStart = $dateSent->subMinutes(10);
        $windowEnd = $dateSent->addMinutes(10);

        $conversationExists = Conversation::query()
            ->where('sender_number', $from)
            ->where('receiver_number', $to)
            ->where('message', $body)
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->exists();

        if ($conversationExists) {
            return true;
        }

        return JobberTextMessage::query()
            ->where('sender_number', $from)
            ->where('receiver_number', $to)
            ->where('messages', $body)
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload($message): array
    {
        $payload = [
            'MessageSid' => $message->sid,
            'SmsSid' => $message->sid,
            'AccountSid' => $message->accountSid,
            'From' => $message->from,
            'To' => $message->to,
            'Body' => (string) ($message->body ?? ''),
            'NumMedia' => (string) ($message->numMedia ?? 0),
            'NumSegments' => (string) ($message->numSegments ?? 0),
            'MessagingServiceSid' => $message->messagingServiceSid,
        ];

        $numMedia = (int) ($message->numMedia ?? 0);
        if ($numMedia > 0) {
            try {
                $media = $this->twilio->fetchMessageMedia($message->sid);
                foreach ($media as $i => $item) {
                    $payload["MediaUrl{$i}"] = $item['url'];
                    $payload["MediaContentType{$i}"] = $item['content_type'];
                }
            } catch (\Throwable $e) {
                Log::warning('twilio:import-inbound-messages media fetch failed', ['sid' => $message->sid, 'error' => $e->getMessage()]);
            }
        }

        return $payload;
    }
}
