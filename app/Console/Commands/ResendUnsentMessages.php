<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\TwilioService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResendUnsentMessages extends Command
{
    protected $signature = 'messages:resend-unsent 
                            {--from=2025-09-05 : Start date/time (YYYY-MM-DD HH:MM:SS or YYYY-MM-DD)}
                            {--to= : End date/time (YYYY-MM-DD HH:MM:SS or YYYY-MM-DD), defaults to now}
                            {--dry-run : Show what would be sent without actually sending}
                            {--limit=0 : Limit number of messages to send (0 = no limit)}';

    protected $description = 'Resend messages that were saved in database but not sent to Twilio (e.g., when send was commented out)';

    protected $twilioService;

    public function __construct(TwilioService $twilioService)
    {
        parent::__construct();
        $this->twilioService = $twilioService;
    }

    public function handle()
    {
        $fromDate = $this->option('from') ?? '2025-09-05';
        $toDate = $this->option('to') ?? now()->format('Y-m-d H:i:s');
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        try {
            // Parse dates - if only date provided, add time
            if (strlen($fromDate) === 10) {
                $fromDate .= ' 00:00:00';
            }
            if (strlen($toDate) === 10) {
                $toDate .= ' 23:59:59';
            }
            
            $startDate = Carbon::parse($fromDate);
            $endDate = Carbon::parse($toDate);
        } catch (\Exception $e) {
            $this->error('Invalid date format. Use YYYY-MM-DD HH:MM:SS or YYYY-MM-DD');
            return 1;
        }

        $this->info("=" . str_repeat("=", 70));
        $this->info("RESENDING UNSENT MESSAGES");
        $this->info("=" . str_repeat("=", 70));
        $this->info("From: {$startDate->format('Y-m-d H:i:s')}");
        $this->info("To: {$endDate->format('Y-m-d H:i:s')}");
        
        if ($dryRun) {
            $this->warn("🔍 DRY RUN MODE - No messages will actually be sent");
        }
        if ($limit > 0) {
            $this->info("Limit: {$limit} messages");
        }
        $this->info("=" . str_repeat("=", 70));
        $this->newLine();

        // Get all messages in the date range
        $query = Conversation::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('sender_number')
            ->whereNotNull('receiver_number')
            ->where('sender_number', '!=', '')
            ->where('receiver_number', '!=', '')
            ->with(['work_order', 'media'])
            ->orderBy('created_at', 'asc');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $messages = $query->get();

        if ($messages->isEmpty()) {
            $this->warn("No messages found in the specified date range");
            return 0;
        }

        $this->info("Found {$messages->count()} messages to process");
        $this->newLine();

        // Group by date for better organization
        $messagesByDate = $messages->groupBy(function ($message) {
            return $message->created_at->format('Y-m-d');
        });

        $totalSent = 0;
        $totalFailed = 0;
        $totalSkipped = 0;

        foreach ($messagesByDate as $date => $dayMessages) {
            $this->info("📅 Date: {$date}");
            $this->info(str_repeat("-", 50));
            
            foreach ($dayMessages as $message) {
                $this->line("");
                $this->info("Message ID: {$message->id} | Work Order: #{$message->work_order_id}");
                $this->line("Time: {$message->created_at->format('H:i:s')} | Type: {$message->conversation_type}");
                $this->line("From: {$message->sender_number}");
                $this->line("To: {$message->receiver_number}");
                
                // Show truncated message
                $messagePreview = strlen($message->message) > 150 
                    ? substr($message->message, 0, 150) . '...' 
                    : $message->message;
                $this->line("Message: {$messagePreview}");
                
                // Check for media
                $mediaUrl = null;
                if ($message->is_mms && $message->media->isNotEmpty()) {
                    $media = $message->media->first();
                    if ($media && $media->media_url) {
                        $mediaUrl = $media->media_url;
                        $this->line("📎 Has MMS attachment");
                    }
                }

                if (!$dryRun) {
                    $this->line("Sending...");
                    
                    try {
                        // Send via Twilio
                        $this->twilioService->sendMessage(
                            $message->receiver_number,
                            $message->sender_number,
                            $message->message,
                            $mediaUrl
                        );
                        
                        $totalSent++;
                        $this->info("✅ Sent successfully!");
                        
                        // Log success
                        Log::info('Unsent message resent successfully', [
                            'conversation_id' => $message->id,
                            'work_order_id' => $message->work_order_id,
                            'from' => $message->sender_number,
                            'to' => $message->receiver_number,
                            'original_created_at' => $message->created_at,
                            'resent_at' => now(),
                        ]);
                        
                        // Small delay to avoid rate limiting
                        usleep(500000); // 0.5 second delay
                        
                    } catch (\Exception $e) {
                        $totalFailed++;
                        $this->error("❌ Failed: " . $e->getMessage());
                        
                        Log::error('Failed to resend unsent message', [
                            'conversation_id' => $message->id,
                            'work_order_id' => $message->work_order_id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                } else {
                    $this->warn("⏭️  Would send (dry run mode)");
                    $totalSent++;
                }
                
                $this->line(str_repeat("-", 50));
            }
            
            $this->newLine();
        }

        // Final Summary
        $this->newLine();
        $this->info("=" . str_repeat("=", 70));
        $this->info("SUMMARY");
        $this->info("=" . str_repeat("=", 70));
        $this->info("Total messages processed: {$messages->count()}");
        
        if (!$dryRun) {
            $this->info("✅ Successfully sent: {$totalSent}");
            if ($totalFailed > 0) {
                $this->error("❌ Failed to send: {$totalFailed}");
            }
            if ($totalSkipped > 0) {
                $this->warn("⏭️  Skipped: {$totalSkipped}");
            }
            
            // Log summary
            Log::info('Resend unsent messages command completed', [
                'date_range' => "{$startDate} to {$endDate}",
                'total_processed' => $messages->count(),
                'sent' => $totalSent,
                'failed' => $totalFailed,
                'skipped' => $totalSkipped,
            ]);
        } else {
            $this->warn("Would have sent {$totalSent} messages (dry run)");
        }

        return $totalFailed > 0 ? 1 : 0;
    }
}