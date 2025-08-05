<?php

namespace App\Console\Commands;

use App\Http\Controllers\JobberAuthController;
use App\Models\JobberToken;
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
    public function handle()
    {
        $token = JobberToken::first();
        
        if (!$token) {
            $this->error('No Jobber token found. Please connect to Jobber first.');
            return 1;
        }
        
        // Check if token expires in the next 15 minutes
        $expiresIn15Minutes = now()->addMinutes(15);
        
        if (!$token->expires_at || now()->isAfter($token->expires_at) || $expiresIn15Minutes->isAfter($token->expires_at)) {
            $this->info('Token is expired or expiring soon. Refreshing...');
            
            try {
                $jobberAuth = new JobberAuthController();
                $newAccessToken = $jobberAuth->refreshAccessToken();
                
                $this->info('Token refreshed successfully!');
                Log::info('Jobber token refreshed via scheduled command');
                
                return 0;
            } catch (\Exception $e) {
                $this->error('Failed to refresh token: ' . $e->getMessage());
                Log::error('Failed to refresh Jobber token via scheduled command', [
                    'error' => $e->getMessage()
                ]);
                
                // Send notification to admin about manual reconnection needed
                // You can implement email/SMS notification here
                
                return 1;
            }
        }
        
        $this->info('Token is still valid. No refresh needed.');
        return 0;
    }
}