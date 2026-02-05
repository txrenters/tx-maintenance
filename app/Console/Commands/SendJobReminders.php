<?php

namespace App\Console\Commands;

use App\Models\JobberTextMessage;
use App\Models\JobberVisit;
use App\Services\TwilioService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendJobReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jobs:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send SMS reminders to tenants for upcoming jobs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $today = Carbon::today();

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

        // 7 days before
        $this->sendMessages($today->copy()->timezone('America/Chicago')->addDays(7), 'notified_7_days', $notifyMessageFor7days);

        // 3 days before
        $this->sendMessages($today->copy()->timezone('America/Chicago')->addDays(3), 'notified_3_days', $notifyMessageFor3days);

    }

    protected function sendMessages(Carbon $scheduled_date, string $notifiedField, string $messageText)
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
        $senderNumber = env('TWILIO_PHONE_NUMBER');

        $TENANT_JSON_API_LINK = 'https://app.propertyware.com/pw/00a/4297818113/JSON?8xDmDzx&shardKey=182255624';

        $response = Http::get($TENANT_JSON_API_LINK);

        if ($response->failed()) {
            Log::error('Failed to fetch client data from Propertyware');

            return;
        }

        $records = $response->json()['records'] ?? [];

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
                    ],
                ]);
                $loggedSample = true;
            }

            $filtered = collect($records)->filter(function ($record) use ($client) {
                // Exact case-insensitive match to prevent matching similar names
                return strtolower(trim($record[4] ?? '')) === strtolower(trim($client ?? ''));
            })->values();

            if ($filtered->isEmpty()) {
                Log::warning('No PropertyWare tenant found for Jobber client', [
                    'jobber_client_name' => $client,
                    'visit_id' => $visit->id,
                ]);

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
                    'propertyware_property_match' => $record[4] ?? 'N/A',
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
                try {
                    $message = str_replace('{CLIENT_NAME}', $clientName, $messageText);
                    $message2 = str_replace('{SCHEDULED_DATE}', $visitDate, $message);

                    Log::info('Sending job reminder SMS', [
                        'client_name' => $clientName,
                        'visit_date' => $visitDate,
                        'to' => $phoneNumber,
                        'notification_type' => $notifiedField,
                    ]);

                    $twilio->sendMessage($phoneNumber, $senderNumber, $message2);

                    $text = JobberTextMessage::create([
                        'messages' => $message2 ?? '',
                        'sender_number' => $senderNumber,
                        'receiver_number' => $phoneNumber,
                        'jobber_id' => $visit->job->id,
                        'sent_at' => $visit->job->start_at,
                    ]);

                    Log::info('Job reminder SMS sent successfully', [
                        'message_id' => $text->id,
                        'client_name' => $clientName,
                        'to' => $phoneNumber,
                    ]);

                } catch (\Throwable $th) {
                    Log::error('Sending message is unsuccesfull:', ['error' => $th->getMessage()]);
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
}
