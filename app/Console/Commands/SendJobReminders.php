<?php

namespace App\Console\Commands;

use App\Models\Jobber;
use App\Models\JobberVisit;
use App\Services\TwilioService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

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

        // 7 days before
        $this->sendMessages($today->copy()->addDays(7), 'notified_7_days', 'Heads up! Your job is scheduled in 7 days.');

        // 3 days before
        $this->sendMessages($today->copy()->addDays(3), 'notified_3_days', 'Reminder: Your job is scheduled in 3 days.');
    }

    protected function sendMessages(Carbon $date, string $notifiedField, string $messageText)
    {
        $scheduled_date = $date;

        $visits = JobberVisit::with(['job.client'])
            ->whereDate('start_at', $scheduled_date)
            ->where($notifiedField, false)
            ->get();

        $sms = new TwilioService();
        $from = env('TWILIO_PHONE_NUMBER');

        foreach ($visits as $visit) {
            
            $client = $visit->job->client->name;

            if ($visit->tenant && $visit->tenant->phone) {
                $message = "{$messageText} (Job ID: {$visit->id})";

                $sms->sendMessage($visit->tenant->phone, $from, $message);

                $visit->{$notifiedField} = true;
                $visit->save();
            }
        }

        Log::info("Sent " . count($visit) . " {$notifiedField} messages for date: {$date->toDateString()}");
    }
}
