<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\WorkOrder;
use App\Services\TwilioService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResendWorkOrderMessages extends Command
{
    protected $signature = 'workorder:resend-messages 
                            {--from= : Start date (YYYY-MM-DD)}
                            {--to= : End date (YYYY-MM-DD), defaults to today}
                            {--after-hours : Only resend messages created after business hours (5 PM - 9 AM)}
                            {--dry-run : Show what would be sent without actually sending}
                            {--work-order= : Specific work order ID to resend messages for}';

    protected $description = 'Resend work order messages from a specific date range';

    protected $twilioService;

    public function __construct(TwilioService $twilioService)
    {
        parent::__construct();
        $this->twilioService = $twilioService;
    }

    public function handle()
    {
        $fromDate = $this->option('from');
        $toDate = $this->option('to') ?? now()->format('Y-m-d');
        $afterHoursOnly = $this->option('after-hours');
        $dryRun = $this->option('dry-run');
        $workOrderId = $this->option('work-order');

        if (!$fromDate) {
            $this->error('Please provide a from date using --from=YYYY-MM-DD');
            return 1;
        }

        try {
            $startDate = Carbon::parse($fromDate)->startOfDay();
            $endDate = Carbon::parse($toDate)->endOfDay();
        } catch (\Exception $e) {
            $this->error('Invalid date format. Please use YYYY-MM-DD');
            return 1;
        }

        $this->info("Resending messages from {$startDate->format('Y-m-d H:i:s')} to {$endDate->format('Y-m-d H:i:s')}");
        
        if ($afterHoursOnly) {
            $this->info("Filtering for messages created after business hours (5 PM - 9 AM)");
        }
        
        if ($dryRun) {
            $this->warn("DRY RUN MODE - No messages will actually be sent");
        }

        // Build the query
        $query = Conversation::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('sender_number')
            ->whereNotNull('receiver_number')
            ->where('sender_number', '!=', '')
            ->where('receiver_number', '!=', '')
            ->orderBy('created_at', 'asc');

        // Filter by work order if specified
        if ($workOrderId) {
            $query->where('work_order_id', $workOrderId);
            $this->info("Filtering for work order ID: {$workOrderId}");
        }

        // Get messages
        $messages = $query->with(['work_order', 'media'])->get();

        // Filter for after-hours if requested
        if ($afterHoursOnly) {
            $messages = $messages->filter(function ($message) {
                $hour = $message->created_at->hour;
                // After 5 PM (17:00) or before 9 AM (09:00)
                return $hour >= 17 || $hour < 9;
            });
        }

        $this->info("Found {$messages->count()} messages to resend");

        if ($messages->isEmpty()) {
            $this->info("No messages found matching the criteria");
            return 0;
        }

        $successCount = 0;
        $failCount = 0;

        $this->withProgressBar($messages, function ($message) use ($dryRun, &$successCount, &$failCount) {
            try {
                // Determine the Twilio phone number based on conversation type
                $twilioNumber = $this->getTwilioNumber($message);
                
                // Prepare media URL if message has media
                $mediaUrl = null;
                if ($message->is_mms && $message->media->isNotEmpty()) {
                    $media = $message->media->first();
                    if ($media && $media->media_url) {
                        $mediaUrl = $media->media_url;
                    }
                }

                // Display message info
                $this->newLine();
                $this->line("Work Order #{$message->work_order->id}: {$message->message}");
                $this->line("From: {$message->sender_number} To: {$message->receiver_number}");
                $this->line("Type: {$message->conversation_type} | Created: {$message->created_at}");
                
                if ($mediaUrl) {
                    $this->line("Media: {$mediaUrl}");
                }

                if (!$dryRun) {
                    // Actually send the message
                    $this->twilioService->sendMessage(
                        $message->receiver_number,
                        $message->sender_number,
                        $message->message,
                        $mediaUrl
                    );
                    
                    $successCount++;
                    $this->info("✓ Message sent successfully");
                    
                    // Log the resend
                    Log::info('Work order message resent', [
                        'work_order_id' => $message->work_order_id,
                        'conversation_id' => $message->id,
                        'from' => $message->sender_number,
                        'to' => $message->receiver_number,
                        'type' => $message->conversation_type,
                        'created_at' => $message->created_at,
                    ]);
                } else {
                    $this->info("✓ Would send message (dry run)");
                    $successCount++;
                }
                
            } catch (\Exception $e) {
                $failCount++;
                $this->error("✗ Failed to send message: " . $e->getMessage());
                
                Log::error('Failed to resend work order message', [
                    'work_order_id' => $message->work_order_id,
                    'conversation_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        $this->newLine(2);
        $this->info("Resend Summary:");
        $this->info("Successfully sent: {$successCount}");
        $this->error("Failed: {$failCount}");

        return 0;
    }

    private function getTwilioNumber($message): string
    {
        // Determine which Twilio number to use based on conversation type
        // This should match your existing logic
        
        switch ($message->conversation_type) {
            case 'vendor_woc':
            case 'vendor':
                // Check if work order has a WOC number assigned
                if ($message->work_order->woc_id) {
                    $wocNumber = $message->work_order->woc->twilioPhoneNumber ?? null;
                    if ($wocNumber) {
                        return $wocNumber;
                    }
                }
                return env('TWILIO_PHONE_NUMBER');
                
            case 'tenant':
            case 'owner':
            case 'vendor_tenant':
            default:
                return env('TWILIO_PHONE_NUMBER');
        }
    }
}