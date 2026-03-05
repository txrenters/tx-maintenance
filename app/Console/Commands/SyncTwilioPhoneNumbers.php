<?php

namespace App\Console\Commands;

use App\Services\TwilioPhoneNumberSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncTwilioPhoneNumbers extends Command
{
    protected $signature = 'twilio:sync-phone-numbers';

    protected $description = 'Sync Twilio phone numbers into local database';

    public function __construct(private readonly TwilioPhoneNumberSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->syncService->sync();

            $message = "Twilio phone numbers synced. Total: {$result['total']}, Created: {$result['created']}, Updated: {$result['updated']}";
            $this->info($message);
            Log::info($message);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to sync Twilio phone numbers: '.$e->getMessage());
            Log::error('Failed to sync Twilio phone numbers', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
