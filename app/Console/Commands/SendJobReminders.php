<?php

namespace App\Console\Commands;

use App\Models\JobberTextMessage;
use App\Models\JobberVisit;
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
                            {--search : Search all TBP visits for the given date(s) against PropertyWare JSON without sending SMS}';

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

        $notifyMessageFor7days = "Dear {CLIENT_NAME},\n
            As part of your Tenant Benefit Package (TBP), we have scheduled the following services on {SCHEDULED_DATE}:
                * Pest control treatment
                * Air filter replacement
                * Occupied inspection
            Please note the following important details:
                * Access & Preparation: You do not need to be present during the visit. We will provide access to our technician. Please secure all valuables and crate any pets. If any areas are inaccessible, a trip charge may be applied in accordance with your lease agreement.
                * Timing: We cannot provide an exact arrival time, as our technicians have multiple appointments, and job durations may vary. However, the technician will call or notify you prior to arrival.
                * Body Cameras: For security and documentation purposes, our technicians wear body cameras during all visits.
                * Filter Access: Filters will only be replaced if they are unobstructed. Please ensure furniture or other items are moved beforehand to allow access.
                * Rescheduling: If the technician is unable to attend for any reason, we will promptly reschedule and notify you.
            Please confirm receipt of this notice and your approval by replying to this message. We appreciate your cooperation and understanding.\n
            Warm regards,
            TexasRenters.com, LLC";

        $notifyMessageFor3days = "Dear {CLIENT_NAME},\n
            This is a friendly reminder of the scheduled visit on {SCHEDULED_DATE} for the * Pest control treatment * Air filter replacement * Occupied inspection.\n
            Please note:\n
                * We are unable to provide an exact arrival time, as our technicians have multiple appointments and job durations may vary. The technician will call or notify you prior to arrival.
                * For safety and efficiency, please ensure all pets are secured in a crate or on a leash before the visit. Technicians will be unable to enter the property otherwise.
            Thank you for your cooperation. Should you have any questions, feel free to reach out to us\n
            Warm regards,
            TexasRenters.com, LLC";

        $reminderConfigurations = [
            3 => [
                'notified_field' => 'notified_3_days',
                'message' => $notifyMessageFor3days,
            ],
            7 => [
                'notified_field' => 'notified_7_days',
                'message' => $notifyMessageFor7days,
            ],
        ];

        $requestedDays = collect($this->option('days'))
            ->map(fn (mixed $day): int => (int) $day)
            ->values();

        if ($requestedDays->isEmpty()) {
            $requestedDays = collect([3, 7]);
        }

        $invalidDays = $requestedDays
            ->reject(fn (int $day): bool => array_key_exists($day, $reminderConfigurations))
            ->all();

        if ($invalidDays !== []) {
            $this->error('Unsupported reminder day override(s): '.implode(', ', $invalidDays).'. Supported values: 3, 7.');

            return self::FAILURE;
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

            $this->sendMessages(
                $today->copy()->timezone(self::COMMAND_TIMEZONE)->addDays($day),
                $configuration['notified_field'],
                $configuration['message']
            );
        }

        return self::SUCCESS;
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

        $TENANT_JSON_API_LINK = 'https://app.propertyware.com/pw/00a/4297818113/JSON?8xDmDzx&shardKey=182255624';
        $response = Http::get($TENANT_JSON_API_LINK);

        if ($response->failed()) {
            $this->error('Failed to fetch PropertyWare JSON.');

            return;
        }

        $body = $response->json();
        $records = $body['records'] ?? [];
        $this->line('PropertyWare JSON loaded: '.count($records).' records');
        $this->line('HTTP status: '.$response->status());

        if (count($records) === 0) {
            $this->error('PropertyWare returned 0 records — API may be down or the response format changed. Cannot match visits.');
            $this->line('Response keys present: '.implode(', ', array_keys($body ?? [])));
            $this->line('Raw response (first 500 chars): '.substr($response->body(), 0, 500));

            return;
        }

        $this->line('');

        foreach ($tbpVisits as $visit) {
            $client = $visit->job->client->name ?? '';
            $jobberKey = $this->normalizeBaseBuildingReference($client);

            $this->line('--- Visit ID: '.$visit->id.' | Jobber address: '.$client.' | Key (first 2 words): '.$jobberKey.' ---');

            $matched = collect($records)->filter(function ($record) use ($client) {
                return $this->buildingReferenceMatches($client, (string) ($record[15] ?? ''));
            })->values();

            if ($matched->isEmpty()) {
                $this->warn('  No match in PropertyWare record[15]');
                $this->outputJsonBuildingSample($records, $client);
            } else {
                $this->info('  Matched '.$matched->count().' PropertyWare record(s):');

                foreach ($matched as $record) {
                    $phone = collect([
                        'Mobile' => (string) ($record[13] ?? ''),
                        'Home' => (string) ($record[14] ?? ''),
                        'Work' => (string) ($record[11] ?? ''),
                        'Other' => (string) ($record[12] ?? ''),
                    ])->first(fn (string $v): bool => $v !== '');

                    $pwKey = $this->normalizeBaseBuildingReference((string) ($record[15] ?? ''));

                    $this->line('    Building : '.($record[15] ?? 'N/A'));
                    $this->line('    PW Key   : '.$pwKey.' (matched Jobber key: '.$jobberKey.')');
                    $this->line('    Tenant   : '.($record[3] ?? 'N/A'));
                    $this->line('    Status   : '.($record[2] ?? 'N/A'));
                    $this->line('    Phone    : '.($phone ?: 'N/A'));
                    $this->line('    Mobile   : '.($record[13] ?: 'N/A'));
                    $this->line('    Home     : '.($record[14] ?: 'N/A'));
                    $this->line('    Work     : '.($record[11] ?: 'N/A'));
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

        $visits = JobberVisit::with(['job.client'])
            ->whereDate('start_at', $scheduled_date)
            ->whereNull('completed_at')
            ->where($notifiedField, false)
            ->get();

        $twilio = new TwilioService;
        $senderNumber = env('TWILIO_PHONE_NUMBER'); // this is for THMP phone number
        $hasVisitColumn = JobberTextMessage::hasVisitColumn();
        $messageColumns = $this->getMessageColumnAvailability();

        $TENANT_JSON_API_LINK = 'https://app.propertyware.com/pw/00a/4297818113/JSON?8xDmDzx&shardKey=182255624';

        $response = Http::get($TENANT_JSON_API_LINK);

        if ($response->failed()) {
            Log::error('Failed to fetch client data from Propertyware');

            return;
        }

        $records = $response->json()['records'] ?? [];

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
                        '11' => $records[0][11] ?? 'N/A',
                        '12' => $records[0][12] ?? 'N/A',
                        '13' => $records[0][13] ?? 'N/A',
                        '14' => $records[0][14] ?? 'N/A',
                        '15' => $records[0][15] ?? 'N/A',
                    ],
                ]);
                $loggedSample = true;
            }

            $filtered = collect($records)->filter(function ($record) use ($client) {
                return $this->buildingReferenceMatches(
                    $client ?? '',
                    (string) ($record[15] ?? '')
                );
            })->values();

            if ($filtered->isEmpty()) {
                $candidateBuildings = $this->propertywareCandidatesWithSameNumber($records, $streetNumber);
                $similarCandidates = $this->propertywareSimilarCandidates($records, $client ?? '');
                $samplePropertywareRecordValue = collect($records)
                    ->map(fn ($record): string => (string) ($record[15] ?? ''))
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
                    $this->line('  PropertyWare record[15]: '.($samplePropertywareRecordValue ?: 'N/A'));
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

            // Collect unique phone numbers with their client names to prevent duplicate messages
            $uniqueRecipients = [];

            foreach ($filtered as $record) {
                $clientStatus = $record[2];
                $clientName = $record[3];

                Log::info('Processing PropertyWare tenant record', [
                    'jobber_client_name' => $client,
                    'propertyware_status' => $clientStatus,
                    'propertyware_tenant_name' => $clientName,
                    'propertyware_client_reference' => $record[15] ?? 'N/A',
                    'propertyware_address' => $record[4] ?? 'N/A',
                ]);

                if (strtolower($clientStatus) !== 'active') {
                    Log::info('Skipping inactive tenant', ['tenant_name' => $clientName, 'status' => $clientStatus]);

                    continue;
                }

                $workingPhoneNumber = collect([
                    $record[11] ?? null,
                    $record[12] ?? null,
                    $record[13] ?? null,
                    $record[14] ?? null,
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

                    continue;
                }

                $formattedNumber = $this->formatNumber($workingPhoneNumber);

                // Deduplicate by phone + name combination (allow same phone with different names)
                $key = $formattedNumber.'|'.$clientName;
                if (! isset($uniqueRecipients[$key])) {
                    $uniqueRecipients[$key] = [
                        'phone' => $formattedNumber,
                        'name' => $clientName,
                        'lease_status' => $clientStatus,
                        'propertyware_building' => (string) ($record[15] ?? ''),
                        'propertyware_address' => (string) ($record[4] ?? ''),
                    ];
                }
            }

            // Send messages to unique phone + name combinations
            $visitDate = Carbon::parse($visit->start_at)->format('l, F d, Y');

            // Mark visit as notified BEFORE sending to prevent duplicates if script crashes mid-send
            $visit->{$notifiedField} = true;
            $visit->save();

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
        }

        Log::info('Number of visit: ('.count($visits).") for date: {$scheduled_date->toDateString()}");
    }

    protected function formatNumber(string $number): string
    {
        $cleanedNumber = preg_replace('/[^0-9]/', '', $number);

        if (empty($cleanedNumber)) {
            Log::error('The provided phone number is invalid', ['phone' => $number]);
        }

        return '+1'.$cleanedNumber;
    }

    protected function buildingReferenceMatches(string $jobberClientName, string $propertywareClientReference): bool
    {
        $normalizedJobberBuildingReference = $this->normalizeBaseBuildingReference($jobberClientName);
        $normalizedPropertywareBuildingReference = $this->normalizeBaseBuildingReference($propertywareClientReference);

        if ($normalizedJobberBuildingReference === '') {
            return false;
        }

        return $normalizedPropertywareBuildingReference !== ''
            && $normalizedJobberBuildingReference === $normalizedPropertywareBuildingReference;
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
        $parts = collect(explode(' ', $this->normalizeBuildingReference($value)))
            ->filter()
            ->values();

        if ($parts->isEmpty()) {
            return '';
        }

        return $parts->take(2)->implode(' ');
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

                $building = (string) ($record[15] ?? '');

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
                $building = $this->normalizeBuildingReference((string) ($record[15] ?? ''));

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
        $building = (string) ($record[15] ?? '');

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
     * values from record[15] that share any non-numeric word with the Jobber client name.
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
            ->map(fn ($record): string => (string) ($record[15] ?? ''))
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
                ->map(fn ($record): string => (string) ($record[15] ?? ''))
                ->filter(fn (string $building): bool => $building !== '')
                ->unique()
                ->take(5)
                ->values();

            $this->line('  JSON 1-by-1 search: no building in record[15] shares any word with "'.$jobberClientName.'"');
            $this->line('  Sample record[15] values from JSON:');

            foreach ($sample as $building) {
                $this->line('    - '.$building);
            }
        } else {
            $this->line('  JSON 1-by-1 search — record[15] buildings sharing a word with "'.$jobberClientName.'":');

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
