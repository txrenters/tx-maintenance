<?php

namespace App\Console\Commands;

use App\Models\JobberTextMessage;
use App\Models\JobberVisit;
use App\Services\TwilioService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

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

        $notifyMessageFor7days = "Hello {CLIENT_NAME}!\n\n
            As part of the Tenant Benefit Package (TBP), we have scheduled the pest control treatment and filter change on Tuesday, 8/12. 
            We’ll also perform an Occupied Inspection. 
            Please secure your valuables and crate pets If areas can't be accessed, a trip charge may be applied as per your lease agreement.
            No need for you to be present; we'll provide access to our technician. 
            You can confirm your approval by sending a message through your tenant portal or contacting us via phone. 
            We can't provide a specific time for the visit, as our technicians have multiple jobs to complete, and the duration of each job can vary but he will notify or call you before arrival. Please note that our technicians wear body cameras during visits. 
            Additionally, filters will only be changed if they are unobstructed. 
            If any furniture or objects are blocking access to the filter, the tenant will need to move them prior to our visit. 
            If for any reason, our technician can't make it, we'll arrange another date and inform you promptly. 
            Please respond if you have received this so our technician can proceed with the inspection. 
            We appreciate your understanding.\n\n 
            Warm regards,\n 
            TexasRenters.com, LLC";

        $notifyMessageFor3days = "Good day {CLIENT_NAME}!\n\n
            Just a quick reminder of the scheduled visit on DATE. 
            We cannot provide an exact arrival time, as our technicians have multiple jobs, and the duration of each job may vary but he will notify or call you before arrival. 
            Please ensure that any pets are secured in a crate or leashed, as technicians will not be able to enter otherwise. 
            Thank you for your cooperation! Let us know if you have any questions. .\n\n 
            Warm regards,\n 
            TexasRenters.com, LLC";

        // 7 days before
        // $this->sendMessages($today->copy()->addDays(7), 'notified_7_days', $notifyMessageFor7days);

        // // 3 days before
        // $this->sendMessages($today->copy()->addDays(3), 'notified_3_days', $notifyMessageFor3days);

        $this->sendMessages($today->copy()->addDays(2), 'notified_7_days', $notifyMessageFor7days);

    }

    protected function sendMessages(Carbon $date, string $notifiedField, string $messageText)
    {
        $scheduled_date = $date;

        $visits = JobberVisit::with(['job.client'])
            ->whereDate('start_at', $scheduled_date)
            ->where($notifiedField, false)
            ->whereNull('completed_at')
            ->get();

        $twilio = new TwilioService;
        $senderNumber = env('TWILIO_PHONE_NUMBER');

        $TENANT_JSON_API_LINK = 'https://app.propertyware.com/pw/00a/4297818113/JSON?8xDmDzx&shardKey=182255624';

        $response = Http::get($TENANT_JSON_API_LINK);

        if ($response->failed()) {
            return response()->json(['error' => 'Failed to fetch data from Propertyware'], 500);
        }

        $records = $response->json()['records'] ?? [];

        foreach ($visits as $visit) {
            $jobTitle = $visit->job->title;
            $visitTitle = $visit->title;

            if (! preg_match('/tenant benefit package|tbp/i', $jobTitle ?? '')) { // Exclude non TBP jobs
                continue;
            }

            if (! preg_match('/tenant benefit package|tbp/i', $visitTitle ?? '')) { // Exclude non TBP visits
                continue;
            }

            $client = $visit->job->client->name;

            $filtered = collect($records)->filter(function ($record) use ($client) {
                return str_contains(strtolower($record[4] ?? ''), $client);
            })->values();

            if ($filtered->isEmpty()) {
                continue;
            }

            foreach ($filtered as $record) {
                $clientStatus = $record[2];
                $clientName = $record[3];
                $mobilePhoneNumber = $this->formatNumber($record[13]);

                if ($clientStatus === 'Active' && ! empty($mobilePhoneNumber)) {
                    $message = str_replace('{CLIENT_NAME}', $clientName, $messageText);

                    $twilio->sendMessage($mobilePhoneNumber, $senderNumber, $message);

                    $visit->{$notifiedField} = true;
                    $visit->save();

                    JobberTextMessage::create([
                        'messages' => $message ?? '',
                        'sender_number' => $senderNumber,
                        'receiver_number' => $mobilePhoneNumber,
                        'jobber_id' => $visit->job->jobber_id,
                    ]);
                }
            }
        }

        Log::info("Sent messages(".count($visits)." for date: {$date->toDateString()}");
    }

    protected function formatNumber(string $number): string
    {
        $cleanedNumber = preg_replace('/[^0-9]/', '', $number);

        if (empty($cleanedNumber)) {
            throw new InvalidArgumentException('The provided phone number is invalid.');
        }

        return '+'.$cleanedNumber;
    }
}
