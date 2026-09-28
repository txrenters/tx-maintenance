<?php

namespace App\Console\Commands;

use App\Mail\JobReminderMail;
use App\Models\Jobber;
use App\Models\JobberTextMessage;
use App\Models\JobberVisit;
use App\Models\Tenants;
use App\Services\AutomatedMessageLogService;
use App\Services\AutomatedMessageTemplates;
use App\Services\JobberAutomationSettings;
use App\Services\MicrosoftGraphMailService;
use App\Services\PhoneFormatter;
use App\Services\PropertyWareTenantReport;
use App\Services\TenantJobberEmailSender;
use App\Services\TwilioService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SendJobReminders extends Command
{
    protected const COMMAND_TIMEZONE = 'America/Chicago';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jobs:send-reminders
                            {--days=* : Send reminders only for the provided day offsets}
                            {--run-date= : Run reminders as if the command were executed on this date (Y-m-d)}
                            {--search : Search all TBP visits for the given date(s) against PropertyWare JSON without sending SMS}
                            {--test-email= : Send a sample reminder email to the given address using each reminder template, then exit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send SMS reminders to tenants for upcoming jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = $this->resolveRunDate();

        if (! $today instanceof Carbon) {
            return self::FAILURE;
        }

        // The 14/7/3/1-day bodies live in the AutomatedMessageTemplates registry
        // (editable from the Automated Messages page) and keep their
        // {CLIENT_NAME}/{SCHEDULED_DATE} tokens: substitution happens
        // per-recipient further down, so raw() is the right accessor here.
        $notifyMessageFor14days = AutomatedMessageTemplates::raw('tenant_job_reminder_14_day');
        $notifyMessageFor7days = AutomatedMessageTemplates::raw('tenant_job_reminder_7_day');
        $notifyMessageFor3days = AutomatedMessageTemplates::raw('tenant_job_reminder_3_day');
        $notifyMessageFor1day = AutomatedMessageTemplates::raw('tenant_job_reminder_1_day');

        $reminderConfigurations = [
            1 => [
                'notified_field' => 'notified_1_days',
                'message' => $notifyMessageFor1day,
            ],
            3 => [
                'notified_field' => 'notified_3_days',
                'message' => $notifyMessageFor3days,
            ],
            7 => [
                'notified_field' => 'notified_7_days',
                'message' => $notifyMessageFor7days,
            ],
            14 => [
                'notified_field' => 'notified_14_days',
                'message' => $notifyMessageFor14days,
            ],
        ];

        $requestedDays = collect($this->option('days'))
            ->map(fn (mixed $day): int => (int) $day)
            ->values();

        if ($requestedDays->isEmpty()) {
            $requestedDays = collect([3, 7, 14]);
        }

        $invalidDays = $requestedDays
            ->reject(fn (int $day): bool => array_key_exists($day, $reminderConfigurations))
            ->all();

        if ($invalidDays !== []) {
            $this->error('Unsupported reminder day override(s): '.implode(', ', $invalidDays).'. Supported values: 1, 3, 7, 14.');

            return self::FAILURE;
        }

        $testEmail = $this->option('test-email');

        if ($testEmail !== null && $testEmail !== '') {
            $templates = [
                14 => $notifyMessageFor14days,
                7 => $notifyMessageFor7days,
                3 => $notifyMessageFor3days,
                1 => $notifyMessageFor1day,
            ];

            $testDaysOption = collect($this->option('days'))
                ->map(fn (mixed $day): int => (int) $day)
                ->filter(fn (int $day): bool => array_key_exists($day, $templates))
                ->unique()
                ->values();

            $daysToSend = $testDaysOption->isEmpty() ? [14, 7, 3, 1] : $testDaysOption->all();

            foreach ($daysToSend as $day) {
                $result = $this->sendTestEmail((string) $testEmail, $templates[$day], $day);

                if ($result !== self::SUCCESS) {
                    return $result;
                }
            }

            return self::SUCCESS;
        }

        if ($this->option('search')) {
            foreach ($requestedDays->unique()->sort()->values() as $day) {
                $this->searchPropertyware(
                    $today->copy()->timezone(self::COMMAND_TIMEZONE)->addDays($day)
                );
            }

            return self::SUCCESS;
        }

        foreach ($requestedDays->unique()->sort()->values() as $day) {
            $configuration = $reminderConfigurations[$day];

            // The 14-day tier shipped with a new jobber_visits column; until
            // production has run that migration, skip just the tiers whose
            // column is missing so the older tiers keep sending.
            if (! $this->notifiedColumnExists($configuration['notified_field'])) {
                $this->warn("Skipping {$day}-day reminders: jobber_visits.{$configuration['notified_field']} column is missing (migration not run yet).");
                Log::warning('Job reminders tier skipped: notified column missing', [
                    'day' => $day,
                    'column' => $configuration['notified_field'],
                ]);

                continue;
            }

            $this->sendMessages(
                $today->copy()->timezone(self::COMMAND_TIMEZONE)->addDays($day),
                $configuration['notified_field'],
                $configuration['message']
            );
        }

        return self::SUCCESS;
    }

    protected function notifiedColumnExists(string $notifiedField): bool
    {
        return Schema::hasColumn('jobber_visits', $notifiedField);
    }

    protected function sendTestEmail(string $to, string $messageText, int $reminderDays): int
    {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address: '.$to);

            return self::FAILURE;
        }

        $sampleName = 'Sample Tenant';
        $sampleDate = Carbon::now(self::COMMAND_TIMEZONE)->next(Carbon::TUESDAY)->format('l, F d, Y');
        $body = str_replace(
            ['{CLIENT_NAME}', '{SCHEDULED_DATE}'],
            [$sampleName, $sampleDate],
            $messageText
        );
        $subjectLine = sprintf('[TEST %d-day] Reminder: Scheduled TBP Service on %s', $reminderDays, $sampleDate);

        try {
            $this->sendReminderEmail($to, new JobReminderMail(
                tenantName: $sampleName,
                visitDate: $sampleDate,
                body: $body,
                subjectLine: $subjectLine,
            ));

            $this->info(sprintf('Test %d-day reminder email sent to %s', $reminderDays, $to));

            return self::SUCCESS;
        } catch (\Throwable $th) {
            $this->error('Failed to send test email: '.$th->getMessage());
            Log::error('Test reminder email failed', ['to' => $to, 'error' => $th->getMessage(), 'reminder_days' => $reminderDays]);

            return self::FAILURE;
        }
    }

    protected function searchPropertyware(Carbon $scheduled_date): void
    {
        $this->line('');
        $this->info('=== Searching PropertyWare for date: '.$scheduled_date->toDateString().' ===');

        $tbpVisits = JobberVisit::with(['job.client'])
            ->whereDate('start_at', $scheduled_date)
            ->get()
            ->filter(function ($visit) {
                return preg_match('/tenant benefit|tbp/i', $visit->job->title ?? '')
                    && preg_match('/tenant benefit|tbp/i', $visit->title ?? '');
            })
            ->values();

        $this->line('TBP visits for date: '.$tbpVisits->count());

        if ($tbpVisits->isEmpty()) {
            $this->warn('No TBP visits found for this date.');

            return;
        }

        $TENANT_JSON_API_LINK = 'https://app.propertyware.com/pw/00a/4377411585/JSON?1ADhXAA&shardKey=182255624';
        $response = Http::timeout(60)->get($TENANT_JSON_API_LINK);

        if ($response->failed()) {
            $this->error('Failed to fetch PropertyWare JSON.');

            return;
        }

        $body = json_decode($response->body(), true, 512, JSON_INVALID_UTF8_IGNORE);
        $records = $body['records'] ?? [];
        $this->line('PropertyWare JSON loaded: '.count($records).' records');
        $this->line('HTTP status: '.$response->status());

        if (count($records) === 0) {
            $this->error('PropertyWare returned 0 records — API may be down or the response format changed. Cannot match visits.');
            $this->line('Response body length: '.strlen($response->body()).' bytes');
            $this->line('json_decode error: '.json_last_error_msg());
            $this->line('Response keys present: '.implode(', ', array_keys($body ?? [])));
            $this->line('Raw response (first 500 chars): '.substr($response->body(), 0, 500));
            $this->line('Raw response (chars 500-1000): '.substr($response->body(), 500, 500));

            return;
        }

        $this->line('');

        foreach ($tbpVisits as $visit) {
            $client = $visit->job->client->name ?? '';
            $jobberKey = $this->normalizeBaseBuildingReference($client);

            $this->line('--- Visit ID: '.$visit->id.' | Jobber address: '.$client.' | Key (first 2 words): '.$jobberKey.' ---');

            $matched = collect($records)->filter(function ($record) use ($client) {
                return $this->buildingReferenceMatches($client, (string) ($record[4] ?? ''));
            })->values();

            if ($matched->isEmpty()) {
                $this->warn('  No match in PropertyWare record[4]');
                $this->outputJsonBuildingSample($records, $client);
            } else {
                $this->info('  Matched '.$matched->count().' PropertyWare record(s):');

                foreach ($matched as $record) {
                    $phone = collect([
                        'Mobile' => (string) ($record[10] ?? ''),
                        'Home' => (string) ($record[13] ?? ''),
                        'Work' => (string) ($record[12] ?? ''),
                    ])->first(fn (string $v): bool => $v !== '');

                    $pwKey = $this->normalizeBaseBuildingReference((string) ($record[4] ?? ''));

                    $this->line('    Building : '.($record[4] ?? 'N/A'));
                    $this->line('    PW Key   : '.$pwKey.' (matched Jobber key: '.$jobberKey.')');
                    $this->line('    Tenant   : '.($record[3] ?? 'N/A'));
                    $this->line('    Status   : '.($record[2] ?? 'N/A'));
                    $this->line('    Phone    : '.($phone ?: 'N/A'));
                    $this->line('    Mobile   : '.($record[10] ?: 'N/A'));
                    $this->line('    Home     : '.($record[13] ?: 'N/A'));
                    $this->line('    Work     : '.($record[12] ?: 'N/A'));
                    $this->line('    Address  : '.($record[4] ?? 'N/A'));
                    $this->line('    ---');
                }
            }

            $this->line('');
        }
    }

    protected function resolveRunDate(): ?Carbon
    {
        $runDate = $this->option('run-date');

        if ($runDate === null || $runDate === '') {
            return Carbon::now(self::COMMAND_TIMEZONE)->startOfDay();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', (string) $runDate, self::COMMAND_TIMEZONE)->startOfDay();
        } catch (\Throwable) {
            $this->error('The run date must use the Y-m-d format, for example 2026-03-31.');

            return null;
        }
    }

    protected function sendMessages(Carbon $scheduled_date, string $notifiedField, string $messageText): void
    {
        // Don’t process if the visit date is on weekend
        if ($scheduled_date->isWeekend()) {
            return;
        }

        // The header kill-switch on the Jobber pages, read once per run and
        // checked BEFORE any visit is flagged as notified — a gate below the
        // flag write at the top of the visit loop would burn the reminder
        // permanently. With both channels off nothing is flagged, so flipping
        // back on within the 7/3-day window resumes cleanly.
        $smsDisabled = JobberAutomationSettings::isDisabled('tenant_job_reminder_sms');
        $emailDisabled = JobberAutomationSettings::isDisabled('tenant_job_reminder_email');

        if ($smsDisabled && $emailDisabled) {
            $this->info('Jobber automation toggle: tenant visit reminders are off; skipping '.$scheduled_date->toDateString());
            Log::info('Job reminders skipped: automation toggled off', ['date' => $scheduled_date->toDateString()]);

            return;
        }

        $visits = JobberVisit::with(['job.client'])
            ->whereDate('start_at', $scheduled_date)
            ->whereNull('completed_at')
            ->where($notifiedField, false)
            ->get();

        $twilio = new TwilioService;
        $senderNumber = env('TWILIO_PHONE_NUMBER'); // this is for THMP phone number
        $hasVisitColumn = JobberTextMessage::hasVisitColumn();
        $messageColumns = $this->getMessageColumnAvailability();

        $TENANT_JSON_API_LINK = 'https://app.propertyware.com/pw/00a/4377411585/JSON?1ADhXAA&shardKey=182255624';

        $response = Http::timeout(60)->get($TENANT_JSON_API_LINK);

        if ($response->failed()) {
            Log::error('Failed to fetch client data from Propertyware');

            return;
        }

        $records = json_decode($response->body(), true, 512, JSON_INVALID_UTF8_IGNORE)['records'] ?? [];

        $isSmallBatch = $visits->count() <= 10;

        foreach ($visits as $visit) {
            $jobTitle = $visit->job->title;
            $visitTitle = $visit->title;

            if (! preg_match('/tenant benefit|tbp/i', $jobTitle ?? '')) { // Exclude non TBP jobs
                continue;
            }

            if (! preg_match('/tenant benefit|tbp/i', $visitTitle ?? '')) { // Exclude non TBP visits
                continue;
            }

            $client = $visit->job->client->name;
            $normalizedClientName = $this->normalizeBuildingReference($client ?? '');
            $normalizedClientKey = $this->normalizeBaseBuildingReference($client ?? '');
            $streetNumber = $this->extractStreetNumber($client ?? '');

            // Log the first record structure for debugging (only log once per run)
            static $loggedSample = false;
            if (! $loggedSample && ! empty($records)) {
                Log::info('PropertyWare Tenant API Sample Record:', [
                    'record_indices' => [
                        '0' => $records[0][0] ?? 'N/A',
                        '1' => $records[0][1] ?? 'N/A',
                        '2' => $records[0][2] ?? 'N/A',
                        '3' => $records[0][3] ?? 'N/A',
                        '4' => $records[0][4] ?? 'N/A',
                        '5' => $records[0][5] ?? 'N/A',
                        '10' => $records[0][10] ?? 'N/A',
                        '11' => $records[0][11] ?? 'N/A',
                        '12' => $records[0][12] ?? 'N/A',
                        '13' => $records[0][13] ?? 'N/A',
                        '14' => $records[0][14] ?? 'N/A',
                    ],
                ]);
                $loggedSample = true;
            }

            $filtered = collect($records)->filter(function ($record) use ($client) {
                return $this->buildingReferenceMatches(
                    $client ?? '',
                    (string) ($record[4] ?? '')
                );
            })->values();

            if ($filtered->isEmpty()) {
                $candidateBuildings = $this->propertywareCandidatesWithSameNumber($records, $streetNumber);
                $similarCandidates = $this->propertywareSimilarCandidates($records, $client ?? '');
                $samplePropertywareRecordValue = collect($records)
                    ->map(fn ($record): string => (string) ($record[4] ?? ''))
                    ->filter(fn (string $value): bool => $value !== '')
                    ->first();

                static $loggedComparisonSample = false;

                if (! $loggedComparisonSample) {
                    $sampleRecord4Value = collect($records)
                        ->map(fn ($record): string => (string) ($record[4] ?? ''))
                        ->filter(fn (string $value): bool => $value !== '')
                        ->first();

                    Log::info('Send job reminders comparison sample', [
                        'jobber_client_name' => $client,
                        'propertyware_record_15_value' => $samplePropertywareRecordValue,
                        'propertyware_record_4_value' => $sampleRecord4Value,
                    ]);

                    $this->line('Comparison sample for this run:');
                    $this->line('  Jobber client: '.$client);
                    $this->line('  PropertyWare record[4]: '.($samplePropertywareRecordValue ?: 'N/A'));
                    $this->line('  PropertyWare record[4]:  '.($sampleRecord4Value ?: 'N/A'));

                    $loggedComparisonSample = true;
                }

                Log::warning('No PropertyWare tenant found for Jobber client', [
                    'jobber_client_name' => $client,
                    'normalized_jobber_client_name' => $normalizedClientName,
                    'jobber_client_key' => $normalizedClientKey,
                    'jobber_street_number' => $streetNumber,
                    'visit' => [
                        'id' => $visit->id,
                        'title' => $visit->title,
                        'start_at' => $visit->start_at,
                    ],
                    'job' => [
                        'id' => $visit->job->id,
                        'number' => $visit->job->job_number,
                        'title' => $visit->job->title,
                    ],
                    'propertyware_candidates_same_number' => $candidateBuildings,
                    'propertyware_similar_candidates' => $similarCandidates,
                    'visit_id' => $visit->id,
                ]);

                $this->warn('No PropertyWare tenant found for Jobber client');
                $this->line('  Jobber client: '.$client);
                $this->line('  Normalized jobber client: '.$normalizedClientName);
                $this->line('  Jobber client key: '.$normalizedClientKey);
                $this->line('  Jobber street number: '.($streetNumber ?? 'N/A'));
                $this->line('  Visit ID: '.$visit->id);
                $this->line('  Visit title: '.($visit->title ?: 'N/A'));
                $this->line('  Visit start_at: '.($visit->start_at ?: 'N/A'));
                $this->line('  Job ID: '.$visit->job->id);
                $this->line('  Job number: '.($visit->job->job_number ?: 'N/A'));
                $this->line('  Job title: '.($visit->job->title ?: 'N/A'));

                if ($candidateBuildings === []) {
                    $this->line('  PropertyWare candidates with same number: none');
                } else {
                    $this->line('  PropertyWare candidates with same number:');

                    $this->outputPropertywareCandidates($candidateBuildings);
                }

                if ($similarCandidates === []) {
                    $this->line('  Similar PropertyWare building candidates: none');
                } else {
                    $this->line('  Similar PropertyWare building candidates:');

                    $this->outputPropertywareCandidates($similarCandidates);
                }

                if ($isSmallBatch) {
                    $this->outputJsonBuildingSample($records, $client ?? '');
                }

                continue;
            }

            $this->logBuildingCollision($visit, $client ?? '', $filtered->all());

            // Collect unique phone numbers and emails with their client names to prevent duplicate messages
            $uniqueRecipients = [];
            $uniqueEmails = [];

            foreach ($filtered as $record) {
                $clientStatus = $record[2];
                $clientName = $record[3];
                $clientEmail = trim((string) ($record[11] ?? ''));

                Log::info('Processing PropertyWare tenant record', [
                    'jobber_client_name' => $client,
                    'propertyware_status' => $clientStatus,
                    'propertyware_tenant_name' => $clientName,
                    'propertyware_client_reference' => $record[4] ?? 'N/A',
                    'propertyware_address' => $record[4] ?? 'N/A',
                ]);

                if (! $this->leaseIsOccupied((string) $clientStatus)) {
                    Log::info('Skipping inactive tenant', ['tenant_name' => $clientName, 'status' => $clientStatus]);

                    continue;
                }

                $enrolledInTbp = strtolower(trim((string) ($record[14] ?? '')));

                if ($enrolledInTbp !== 'yes') {
                    Log::info('Skipping tenant not enrolled in Tenant Benefits Package', [
                        'tenant_name' => $clientName,
                        'enrolled_in_tbp' => $record[14] ?? '',
                    ]);

                    continue;
                }

                $workingPhoneNumber = collect([
                    $record[10] ?? null,
                    $record[13] ?? null,
                    $record[12] ?? null,
                ])
                    ->map(fn ($number) => trim((string) $number))
                    ->first(fn ($number) => $number !== '');

                if (empty($workingPhoneNumber)) {
                    Log::warning("Skipped sending message: empty formatted phone number for client {$clientName}");

                    activity()
                        ->performedOn($visit)
                        ->event('jobber_not_sent')
                        ->withProperties([
                            'jobber_error_message' => 'We could not find the phone number for tenant: '.$clientName,
                        ])
                        ->log('Job #'.$visit->job->job_number.' - Text Message Failed');
                } else {
                    $formattedNumber = $this->formatNumber($workingPhoneNumber);

                    // Deduplicate by phone + name combination (allow same phone with different names)
                    $key = $formattedNumber.'|'.$clientName;
                    if (! isset($uniqueRecipients[$key])) {
                        $uniqueRecipients[$key] = [
                            'phone' => $formattedNumber,
                            'name' => $clientName,
                            'lease_status' => $clientStatus,
                            'propertyware_building' => (string) ($record[4] ?? ''),
                            'propertyware_address' => (string) ($record[4] ?? ''),
                        ];
                    }
                }

                if ($clientEmail !== '' && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                    $emailKey = strtolower($clientEmail);

                    if (! isset($uniqueEmails[$emailKey])) {
                        $uniqueEmails[$emailKey] = [
                            'email' => $clientEmail,
                            'name' => $clientName,
                            'lease_status' => $clientStatus,
                            'propertyware_building' => (string) ($record[4] ?? ''),
                            'propertyware_address' => (string) ($record[4] ?? ''),
                        ];
                    }
                }
            }

            // Send messages to unique phone + name combinations
            $visitDate = Carbon::parse($visit->start_at)->format('l, F d, Y');

            // Mark visit as notified BEFORE sending to prevent duplicates if script crashes mid-send
            $visit->{$notifiedField} = true;
            $visit->save();

            // With only one channel muted the visit is still flagged by the
            // other, so the muted channel's copy is dropped, not queued —
            // "off means dropped", same as the vendor-assignment gate.
            if ($smsDisabled) {
                $uniqueRecipients = [];
            }

            if ($emailDisabled) {
                $uniqueEmails = [];
            }

            foreach ($uniqueRecipients as $recipient) {
                $phoneNumber = $recipient['phone'];
                $clientName = $recipient['name'];
                $message = str_replace('{CLIENT_NAME}', $clientName, $messageText);
                $message2 = str_replace('{SCHEDULED_DATE}', $visitDate, $message);

                try {
                    Log::info('Sending job reminder SMS', [
                        'client_name' => $clientName,
                        'jobber_client_name' => $client,
                        'matched_propertyware_building' => $recipient['propertyware_building'],
                        'visit_date' => $visitDate,
                        'to' => $phoneNumber,
                        'notification_type' => $notifiedField,
                    ]);

                    $twilioMessage = $twilio->sendMessage($phoneNumber, $senderNumber, $message2);

                    $payload = [
                        'messages' => $message2 ?? '',
                        'sender_number' => $senderNumber,
                        'receiver_number' => $phoneNumber,
                        'jobber_id' => $visit->job->id,
                    ];

                    if ($hasVisitColumn) {
                        $payload['jobber_visit_id'] = $visit->id;
                    }

                    if ($messageColumns['status']) {
                        $payload['status'] = $twilioMessage->status ?? 'queued';
                    }
                    if ($messageColumns['sent_at']) {
                        $payload['sent_at'] = now();
                    }
                    if ($messageColumns['error_message']) {
                        $payload['error_message'] = null;
                    }
                    if ($messageColumns['twilio_sid']) {
                        $payload['twilio_sid'] = $twilioMessage->sid ?? null;
                    }
                    if ($messageColumns['twilio_status']) {
                        $payload['twilio_status'] = $twilioMessage->status ?? 'queued';
                    }
                    if ($messageColumns['twilio_status_updated_at']) {
                        $payload['twilio_status_updated_at'] = now();
                    }
                    if ($messageColumns['twilio_error_code']) {
                        $payload['twilio_error_code'] = null;
                    }
                    if ($messageColumns['twilio_error_message']) {
                        $payload['twilio_error_message'] = null;
                    }

                    $text = JobberTextMessage::create($payload);

                    AutomatedMessageLogService::log(
                        AutomatedMessageLogService::CHANNEL_SMS,
                        'tenant',
                        'tenant_job_reminder_sms',
                        $phoneNumber,
                        $visit->job,
                        $message2,
                        [
                            'jobber_id' => $visit->job->id,
                            'jobber_visit_id' => $visit->id,
                            'job_number' => $visit->job->job_number,
                            'jobber_text_message_id' => $text->id,
                        ],
                    );

                    Log::info('Job reminder SMS sent successfully', [
                        'message_id' => $text->id,
                        'client_name' => $clientName,
                        'jobber_client_name' => $client,
                        'visit_id' => $visit->id,
                        'job_id' => $visit->job->id,
                        'job_number' => $visit->job->job_number,
                        'matched_propertyware_building' => $recipient['propertyware_building'],
                        'propertyware_tenant_name' => $clientName,
                        'propertyware_lease_status' => $recipient['lease_status'],
                        'visit_date' => $visitDate,
                        'notification_type' => $notifiedField,
                        'to' => $phoneNumber,
                    ]);

                } catch (\Throwable $th) {
                    Log::error('Sending message is unsuccesfull:', ['error' => $th->getMessage()]);

                    $failedPayload = [
                        'messages' => $message2 ?? '',
                        'sender_number' => $senderNumber,
                        'receiver_number' => $phoneNumber,
                        'jobber_id' => $visit->job->id,
                    ];

                    if ($hasVisitColumn) {
                        $failedPayload['jobber_visit_id'] = $visit->id;
                    }

                    if ($messageColumns['status']) {
                        $failedPayload['status'] = 'failed';
                    }
                    if ($messageColumns['sent_at']) {
                        $failedPayload['sent_at'] = now();
                    }
                    if ($messageColumns['error_message']) {
                        $failedPayload['error_message'] = $th->getMessage();
                    }
                    if ($messageColumns['twilio_sid']) {
                        $failedPayload['twilio_sid'] = null;
                    }
                    if ($messageColumns['twilio_status']) {
                        $failedPayload['twilio_status'] = 'failed';
                    }
                    if ($messageColumns['twilio_status_updated_at']) {
                        $failedPayload['twilio_status_updated_at'] = now();
                    }
                    if ($messageColumns['twilio_error_code']) {
                        $failedPayload['twilio_error_code'] = $th->getCode() ? (string) $th->getCode() : null;
                    }
                    if ($messageColumns['twilio_error_message']) {
                        $failedPayload['twilio_error_message'] = $th->getMessage();
                    }

                    JobberTextMessage::create($failedPayload);
                }
            }

            foreach ($uniqueEmails as $recipient) {
                $emailAddress = $recipient['email'];
                $recipientName = $recipient['name'];
                $message = str_replace('{CLIENT_NAME}', $recipientName, $messageText);
                $message2 = str_replace('{SCHEDULED_DATE}', $visitDate, $message);

                // Lead the subject with the property so staff can tell at a
                // glance which property an email is about; fall back to the
                // Jobber job number when no property reference is available.
                $propertyLabel = trim((string) ($recipient['propertyware_address'] ?? ''));
                if ($propertyLabel === '' || strcasecmp($propertyLabel, 'N/A') === 0) {
                    $propertyLabel = $visit->job->job_number ? 'Job #'.$visit->job->job_number : '';
                }

                $subjectLine = 'Reminder: Scheduled TBP Service on '.$visitDate;
                if ($propertyLabel !== '') {
                    $subjectLine = $propertyLabel.' - '.$subjectLine;
                }

                try {
                    Log::info('Sending job reminder email', [
                        'client_name' => $recipientName,
                        'jobber_client_name' => $client,
                        'matched_propertyware_building' => $recipient['propertyware_building'],
                        'visit_date' => $visitDate,
                        'to' => $emailAddress,
                        'notification_type' => $notifiedField,
                    ]);

                    $this->sendReminderEmail($emailAddress, new JobReminderMail(
                        tenantName: $recipientName,
                        visitDate: $visitDate,
                        body: $message2,
                        subjectLine: $subjectLine,
                    ), true, [
                        'jobber_visit_id' => $visit->id,
                        'jobber_job_id' => $visit->job->id,
                        'jobber_job_number' => $visit->job->job_number,
                        'notification_type' => $notifiedField,
                        'visit_date' => $visitDate,
                    ]);

                    AutomatedMessageLogService::log(
                        AutomatedMessageLogService::CHANNEL_EMAIL,
                        'tenant',
                        'tenant_job_reminder_email',
                        $emailAddress,
                        $visit->job,
                        extra: [
                            'jobber_id' => $visit->job->id,
                            'jobber_visit_id' => $visit->id,
                            'job_number' => $visit->job->job_number,
                            'subject' => $subjectLine,
                        ],
                    );

                    Log::info('Job reminder email sent successfully', [
                        'client_name' => $recipientName,
                        'jobber_client_name' => $client,
                        'visit_id' => $visit->id,
                        'job_id' => $visit->job->id,
                        'job_number' => $visit->job->job_number,
                        'matched_propertyware_building' => $recipient['propertyware_building'],
                        'visit_date' => $visitDate,
                        'notification_type' => $notifiedField,
                        'to' => $emailAddress,
                    ]);
                } catch (\Throwable $th) {
                    Log::error('Sending job reminder email failed', [
                        'client_name' => $recipientName,
                        'jobber_client_name' => $client,
                        'visit_id' => $visit->id,
                        'job_id' => $visit->job->id,
                        'visit_date' => $visitDate,
                        'to' => $emailAddress,
                        'error' => $th->getMessage(),
                    ]);
                }
            }
        }

        Log::info('Number of visit: ('.count($visits).") for date: {$scheduled_date->toDateString()}");
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function sendReminderEmail(string $to, JobReminderMail $mail, bool $recordHistory = false, array $metadata = []): void
    {
        $html = $mail->render();
        $mailbox = (string) config('services.microsoft.job_reminder_mailbox');

        if (! $recordHistory) {
            app(MicrosoftGraphMailService::class)->sendMail($to, [], $mail->subjectLine, $html, mailbox: $mailbox);

            return;
        }

        $matchingTenants = Tenants::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($to)])
            ->get();
        $tenant = $matchingTenants->count() === 1
            ? $matchingTenants->first()
            : $matchingTenants->first(fn (Tenants $candidate): bool => strtolower(trim($candidate->first_name.' '.$candidate->last_name))
                === strtolower(trim($mail->tenantName)));

        if (! $tenant || $matchingTenants->filter(fn (Tenants $candidate): bool => strtolower(trim($candidate->first_name.' '.$candidate->last_name))
            === strtolower(trim($mail->tenantName)))->count() > 1) {
            Log::warning('Job reminder email history could not be linked unambiguously to a tenant', ['to' => $to]);
            app(MicrosoftGraphMailService::class)->sendMail($to, [], $mail->subjectLine, $html, mailbox: $mailbox);

            return;
        }

        $job = isset($metadata['jobber_job_id'])
            ? Jobber::query()->find((int) $metadata['jobber_job_id'])
            : null;

        if ($job === null) {
            app(MicrosoftGraphMailService::class)->sendMail($to, [], $mail->subjectLine, $html, mailbox: $mailbox);
            Log::warning('Job reminder email history could not be linked to a Jobber job', ['to' => $to]);

            return;
        }

        app(TenantJobberEmailSender::class)->send(
            tenant: $tenant,
            job: $job,
            to: $to,
            subject: $mail->subjectLine,
            html: $html,
            metadata: $metadata,
            trustedHtml: true,
        );
    }

    protected function formatNumber(string $number): string
    {
        $cleanedNumber = preg_replace('/[^0-9]/', '', $number);

        if (empty($cleanedNumber)) {
            Log::error('The provided phone number is invalid', ['phone' => $number]);
        }

        // e164 keeps a number that already carries the country code from
        // gaining a second "1" ("+11832..."); numbers too short to send fall
        // back to the old prefix so the failure still surfaces at Twilio with
        // the log line above.
        return PhoneFormatter::e164($number) ?? '+1'.$cleanedNumber;
    }

    /**
     * The same rule the Send notification button uses: identical names, or
     * the same street number with the same street name whether or not either
     * side carries the street suffix ("418 Drennan St" ~ "418 Drennan").
     * Staff type the PropertyWare building name without the suffix on about
     * four in ten buildings, and an exact comparison skipped every one.
     */
    protected function buildingReferenceMatches(string $jobberClientName, string $propertywareClientReference): bool
    {
        return PropertyWareTenantReport::looksLikeSameBuilding(
            PropertyWareTenantReport::normalize($jobberClientName),
            PropertyWareTenantReport::normalize($propertywareClientReference),
        );
    }

    /**
     * A tenant still living in the house: on an active lease, one who has
     * given notice, or one gone month-to-month. Draft and eviction leases are
     * not reminded. Same reading as the Send notification button.
     */
    protected function leaseIsOccupied(string $leaseStatus): bool
    {
        $status = strtolower(trim($leaseStatus));

        return str_starts_with($status, 'active') || str_starts_with($status, 'going mtm');
    }

    /**
     * The loose match can, in principle, pair one Jobber client with two
     * different PropertyWare buildings that share a number and street name.
     * Nothing on the report does today; log it the day it happens so the
     * names can be corrected before a stranger is texted twice.
     *
     * @param  array<int, array<int, mixed>>  $matchedRecords
     */
    protected function logBuildingCollision(JobberVisit $visit, string $jobberClientName, array $matchedRecords): void
    {
        $buildings = collect($matchedRecords)
            ->map(fn (array $record): string => trim((string) ($record[4] ?? '')))
            ->filter()
            ->unique(fn (string $building): string => PropertyWareTenantReport::normalize($building))
            ->values();

        if ($buildings->count() <= 1) {
            return;
        }

        Log::warning('Jobber client matched more than one PropertyWare building', [
            'jobber_client_name' => $jobberClientName,
            'job_number' => $visit->job->job_number,
            'visit_id' => $visit->id,
            'propertyware_buildings' => $buildings->all(),
        ]);

        $this->warn('Jobber client "'.$jobberClientName.'" matched more than one PropertyWare building: '.$buildings->implode(', '));
    }

    protected function normalizeBuildingReference(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s]/u', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }

    protected function normalizeBaseBuildingReference(string $value): string
    {
        $streetSuffixes = [
            'avenue' => 'ave', 'ave' => 'ave',
            'boulevard' => 'blvd', 'blvd' => 'blvd',
            'circle' => 'cir', 'cir' => 'cir',
            'court' => 'ct', 'ct' => 'ct',
            'drive' => 'dr', 'dr' => 'dr',
            'highway' => 'hwy', 'hwy' => 'hwy',
            'lane' => 'ln', 'ln' => 'ln',
            'parkway' => 'pkwy', 'pkwy' => 'pkwy',
            'place' => 'pl', 'pl' => 'pl',
            'road' => 'rd', 'rd' => 'rd',
            'street' => 'st', 'st' => 'st',
            'terrace' => 'ter', 'ter' => 'ter',
            'trail' => 'trl', 'trl' => 'trl',
        ];

        $parts = collect(explode(' ', $this->normalizeBuildingReference($value)))
            ->filter()
            ->map(fn (string $part): string => $streetSuffixes[$part] ?? $part)
            ->values();

        if ($parts->isEmpty()) {
            return '';
        }

        return $parts->implode(' ');
    }

    protected function extractStreetNumber(string $value): ?string
    {
        $normalizedValue = $this->normalizeBuildingReference($value);

        if ($normalizedValue === '') {
            return null;
        }

        return Str::of($normalizedValue)->explode(' ')->first();
    }

    /**
     * @param  array<int, mixed>  $records
     * @return array<int, array{
     *     propertyware_building: string,
     *     normalized_propertyware_building: string,
     *     propertyware_building_key: string,
     *     lease_status: string,
     *     tenant_name: string
     * }>
     */
    protected function propertywareCandidatesWithSameNumber(array $records, ?string $streetNumber): array
    {
        return collect($records)
            ->filter(function ($record) use ($streetNumber) {
                if ($streetNumber === null) {
                    return false;
                }

                $building = (string) ($record[4] ?? '');

                return $this->extractStreetNumber($building) === $streetNumber;
            })
            ->map(fn ($record) => $this->mapPropertywareCandidate($record))
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $records
     * @return array<int, array{
     *     propertyware_building: string,
     *     normalized_propertyware_building: string,
     *     propertyware_building_key: string,
     *     lease_status: string,
     *     tenant_name: string
     * }>
     */
    protected function propertywareSimilarCandidates(array $records, string $jobberClientName): array
    {
        $searchPhrase = $this->streetSearchPhrase($jobberClientName);

        if ($searchPhrase === '') {
            return [];
        }

        return collect($records)
            ->filter(function ($record) use ($searchPhrase) {
                $building = $this->normalizeBuildingReference((string) ($record[4] ?? ''));

                return str_contains($building, $searchPhrase);
            })
            ->map(fn ($record) => $this->mapPropertywareCandidate($record))
            ->unique(fn (array $candidate): string => implode('|', [
                $candidate['propertyware_building'],
                $candidate['tenant_name'],
            ]))
            ->take(10)
            ->values()
            ->all();
    }

    protected function streetSearchPhrase(string $value): string
    {
        $parts = collect(explode(' ', $this->normalizeBuildingReference($value)))
            ->filter()
            ->reject(fn (string $part): bool => is_numeric($part))
            ->reject(fn (string $part): bool => in_array($part, [
                'aly',
                'ave',
                'avenue',
                'blvd',
                'boulevard',
                'cir',
                'circle',
                'court',
                'ct',
                'dr',
                'drive',
                'hwy',
                'highway',
                'lane',
                'ln',
                'loop',
                'parkway',
                'pkwy',
                'pl',
                'place',
                'rd',
                'road',
                'st',
                'street',
                'ter',
                'terrace',
                'trl',
                'trail',
                'way',
            ], true))
            ->values();

        return $parts->take(3)->implode(' ');
    }

    /**
     * @param  array<int, mixed>  $record
     * @return array{
     *     propertyware_building: string,
     *     normalized_propertyware_building: string,
     *     propertyware_building_key: string,
     *     lease_status: string,
     *     tenant_name: string
     * }
     */
    protected function mapPropertywareCandidate(array $record): array
    {
        $building = (string) ($record[4] ?? '');

        return [
            'propertyware_building' => $building,
            'normalized_propertyware_building' => $this->normalizeBuildingReference($building),
            'propertyware_building_key' => $this->normalizeBaseBuildingReference($building),
            'lease_status' => (string) ($record[2] ?? ''),
            'tenant_name' => (string) ($record[3] ?? ''),
        ];
    }

    /**
     * For small batches, search the PropertyWare JSON 1-by-1 and output unique building
     * values from record[4] that share any non-numeric word with the Jobber client name.
     *
     * @param  array<int, mixed>  $records
     */
    protected function outputJsonBuildingSample(array $records, string $jobberClientName): void
    {
        $words = collect(explode(' ', $this->normalizeBuildingReference($jobberClientName)))
            ->filter()
            ->reject(fn (string $w): bool => is_numeric($w))
            ->values();

        if ($words->isEmpty()) {
            return;
        }

        $matches = collect($records)
            ->map(fn ($record): string => (string) ($record[4] ?? ''))
            ->filter(fn (string $building): bool => $building !== '')
            ->unique()
            ->filter(function (string $building) use ($words): bool {
                $normalized = $this->normalizeBuildingReference($building);

                foreach ($words as $word) {
                    if (str_contains($normalized, $word)) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->take(10);

        if ($matches->isEmpty()) {
            $sample = collect($records)
                ->map(fn ($record): string => (string) ($record[4] ?? ''))
                ->filter(fn (string $building): bool => $building !== '')
                ->unique()
                ->take(5)
                ->values();

            $this->line('  JSON 1-by-1 search: no building in record[4] shares any word with "'.$jobberClientName.'"');
            $this->line('  Sample record[4] values from JSON:');

            foreach ($sample as $building) {
                $this->line('    - '.$building);
            }
        } else {
            $this->line('  JSON 1-by-1 search — record[4] buildings sharing a word with "'.$jobberClientName.'":');

            foreach ($matches as $building) {
                $this->line('    - '.$building);
            }
        }
    }

    /**
     * @param  array<int, array{
     *     propertyware_building: string,
     *     normalized_propertyware_building: string,
     *     propertyware_building_key: string,
     *     lease_status: string,
     *     tenant_name: string
     * }>  $candidates
     */
    protected function outputPropertywareCandidates(array $candidates): void
    {
        foreach ($candidates as $candidateBuilding) {
            $this->line('    - Building: '.($candidateBuilding['propertyware_building'] ?: 'N/A'));
            $this->line('      Building normalized: '.($candidateBuilding['normalized_propertyware_building'] ?: 'N/A'));
            $this->line('      Building key: '.($candidateBuilding['propertyware_building_key'] ?: 'N/A'));
            $this->line('      Lease status: '.($candidateBuilding['lease_status'] ?: 'N/A'));
            $this->line('      Tenant name: '.($candidateBuilding['tenant_name'] ?: 'N/A'));
        }
    }

    /**
     * @return array{
     *     status: bool,
     *     sent_at: bool,
     *     error_message: bool,
     *     twilio_sid: bool,
     *     twilio_status: bool,
     *     twilio_status_updated_at: bool,
     *     twilio_error_code: bool,
     *     twilio_error_message: bool
     * }
     */
    protected function getMessageColumnAvailability(): array
    {
        return [
            'status' => Schema::hasColumn('jobber_text_messages', 'status'),
            'sent_at' => Schema::hasColumn('jobber_text_messages', 'sent_at'),
            'error_message' => Schema::hasColumn('jobber_text_messages', 'error_message'),
            'twilio_sid' => Schema::hasColumn('jobber_text_messages', 'twilio_sid'),
            'twilio_status' => Schema::hasColumn('jobber_text_messages', 'twilio_status'),
            'twilio_status_updated_at' => Schema::hasColumn('jobber_text_messages', 'twilio_status_updated_at'),
            'twilio_error_code' => Schema::hasColumn('jobber_text_messages', 'twilio_error_code'),
            'twilio_error_message' => Schema::hasColumn('jobber_text_messages', 'twilio_error_message'),
        ];
    }
}
