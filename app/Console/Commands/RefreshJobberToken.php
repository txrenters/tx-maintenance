<?php

namespace App\Console\Commands;

use App\Exceptions\JobberReconnectRequiredException;
use App\Models\JobberToken;
use App\Services\JobberTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshJobberToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jobber:refresh-token';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh Jobber access token if it\'s about to expire';

    /**
     * Execute the console command.
     */
    public function handle(JobberTokenService $tokens): int
    {
        $token = JobberToken::first();

        if (! $token) {
            $this->error('No Jobber token found. Please connect to Jobber first.');

            return 1;
        }

        if ($tokens->needsReconnect()) {
            // Already flagged and alerted; keep the scheduled run quiet instead
            // of re-burning the dead refresh token every 30 minutes.
            $this->error('Jobber connection lost. An admin must reconnect at /jobber-connect (see the IT Tools page).');

            return 1;
        }

        // Check if token expires in the next 15 minutes
        $expiresIn15Minutes = now()->addMinutes(15);

        if (! $token->expires_at || now()->isAfter($token->expires_at) || $expiresIn15Minutes->isAfter($token->expires_at)) {
            $this->info('Token is expired or expiring soon. Refreshing...');

            try {
                $tokens->refreshAccessToken();

                $this->info('Token refreshed successfully!');
                Log::info('Jobber token refreshed via scheduled command');

                return 0;
            } catch (JobberReconnectRequiredException $e) {
                // The service has flagged the connection dead and alerted staff.
                $this->error($e->getMessage());

                return 1;
            } catch (\Exception $e) {
                $this->error('Failed to refresh token: '.$e->getMessage());
                Log::error('Failed to refresh Jobber token via scheduled command', [
                    'error' => $e->getMessage(),
                ]);

                return 1;
            }
        }

        $this->info('Token is still valid. No refresh needed.');

        return 0;
    }
}
