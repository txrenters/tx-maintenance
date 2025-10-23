<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\TwilioService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckAndResendFailedMessages extends Command
{
    protected $signature = 'messages:check-and-resend 
                            {--date= : Specific date to check (YYYY-MM-DD), defaults to yesterday}
                            {--hours=24 : Number of hours to look back from the date}
                            {--dry-run : Show what would be sent without actually sending}';

    protected $description = 'Check for messages that may have failed after business hours and resend them';

    protected $twilioService;

    public function __construct(TwilioService $twilioService)
    {
        parent::__construct();
        $this->twilioService = $twilioService;
    }

    public function handle()
    {
        $date = $this->option('date') ?? now()->subDay()->format('Y-m-d');
        $hours = $this->option('hours') ?? 24;
        $dryRun = $this->option('dry-run');

        try {
            $endDate = Carbon::parse($date)->endOfDay();
            $startDate = $endDate->copy()->subHours($hours);
        } catch (\Exception $e) {
            $this->error('Invalid date format. Please use YYYY-MM-DD');

            return 1;
        }

        $this->info("Checking messages from {$startDate->format('Y-m-d H:i:s')} to {$endDate->format('Y-m-d H:i:s')}");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No messages will actually be sent');
        }

        // Get messages created after 5 PM or before 9 AM
        $messages = Conversation::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('sender_number')
            ->whereNotNull('receiver_number')
            ->where('sender_number', '!=', '')
            ->where('receiver_number', '!=', '')
            ->where(function ($query) {
                $query->whereTime('created_at', '>=', '17:00:00')  // After 5 PM
                    ->orWhereTime('created_at', '<', '09:00:00'); // Before 9 AM
            })
            ->with(['work_order', 'media'])
            ->orderBy('created_at', 'asc')
            ->get();

        $this->info("Found {$messages->count()} messages created after business hours");

        if ($messages->isEmpty()) {
            $this->info('No after-hours messages found');

            return 0;
        }

        // Group messages by work order for better display
        $messagesByWorkOrder = $messages->groupBy('work_order_id');

        $this->info("Messages found in {$messagesByWorkOrder->count()} work orders");
        $this->newLine();

        $totalResent = 0;
        $totalFailed = 0;

        foreach ($messagesByWorkOrder as $workOrderId => $workOrderMessages) {
            $workOrder = $workOrderMessages->first()->work_order;

            $this->info("Work Order #{$workOrderId}:");
            $this->line("  Location: {$workOrder->location}");
            $this->line("  Created: {$workOrder->created_at->format('Y-m-d H:i:s')}");
            $this->line("  Messages: {$workOrderMessages->count()}");
            $this->newLine();

            foreach ($workOrderMessages as $message) {
                $time = $message->created_at->format('H:i:s');
                $this->line("  [{$time}] {$message->conversation_type}");
                $this->line("  From: {$message->sender_number}");
                $this->line("  To: {$message->receiver_number}");
                $this->line('  Message: '.substr($message->message, 0, 100).(strlen($message->message) > 100 ? '...' : ''));

                if ($message->is_mms && $message->media->isNotEmpty()) {
                    $this->line('  Has MMS attachment');
                }

                if (! $dryRun) {
                    try {
                        // Get media URL if exists
                        $mediaUrl = null;
                        if ($message->is_mms && $message->media->isNotEmpty()) {
                            $mediaUrl = $message->media->first()->media_url;
                        }

                        // Send the message
                        $this->twilioService->sendMessage(
                            $message->receiver_number,
                            $message->sender_number,
                            $message->message,
                            $mediaUrl
                        );

                        $this->info('  ✓ Resent successfully');
                        $totalResent++;

                        Log::info('After-hours message resent', [
                            'work_order_id' => $workOrderId,
                            'conversation_id' => $message->id,
                            'original_time' => $message->created_at,
                        ]);

                    } catch (\Exception $e) {
                        $this->error('  ✗ Failed to resend: '.$e->getMessage());
                        $totalFailed++;

                        Log::error('Failed to resend after-hours message', [
                            'work_order_id' => $workOrderId,
                            'conversation_id' => $message->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    $this->warn('  ⚠ Would resend (dry run mode)');
                    $totalResent++;
                }

                $this->newLine();
            }

            $this->line(str_repeat('-', 60));
            $this->newLine();
        }

        // Summary
        $this->info('Summary:');
        $this->info("Total messages found: {$messages->count()}");

        if (! $dryRun) {
            $this->info("Successfully resent: {$totalResent}");
            if ($totalFailed > 0) {
                $this->error("Failed to resend: {$totalFailed}");
            }
        } else {
            $this->warn("Would resend: {$totalResent} messages (dry run)");
        }

        return 0;
    }
}
