<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportAllVendorsCommand extends Command
{
    protected $signature = 'import:all-vendors {--limit= : Stop after importing this many vendors (for local testing)}';

    protected $description = 'Import and update vendors from PropertyWare';

    public function handle(): int
    {
        $headers = [
            'x-propertyware-client-id' => config('services.propertyware.client_id'),
            'x-propertyware-client-secret' => config('services.propertyware.client_secret_key'),
            'x-propertyware-system-id' => config('services.propertyware.system_id'),
        ];

        $maxToImport = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $batchSize = $maxToImport !== null ? min(500, $maxToImport) : 500;
        $offset = 0;
        $totalProcessed = 0;
        $created = 0;
        $updated = 0;

        Log::info('Vendor import started.', ['limit' => $maxToImport]);
        $this->info('Importing vendors from PropertyWare...');

        try {
            while (true) {
                $vendors = $this->fetchBatch($headers, $batchSize, $offset);

                if ($vendors === null) {
                    // All retries exhausted. Abort rather than advance the
                    // offset: with PropertyWare down every page returns null
                    // and the "skip this batch" loop never terminates.
                    $this->error("PropertyWare could not be fetched at offset {$offset}; aborting this run.");

                    return Command::FAILURE;
                }

                if (empty($vendors)) {
                    break;
                }

                if ($maxToImport !== null && $totalProcessed + count($vendors) > $maxToImport) {
                    $vendors = array_slice($vendors, 0, $maxToImport - $totalProcessed);
                }

                foreach ($vendors as $vendorData) {
                    $result = $this->upsertVendor((array) $vendorData);
                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    }
                }

                $totalProcessed += count($vendors);
                $offset += $batchSize;

                $this->info("Processed {$totalProcessed} vendors so far...");

                if ($maxToImport !== null && $totalProcessed >= $maxToImport) {
                    break;
                }

                if (count($vendors) < $batchSize) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Vendor import failed: '.$e->getMessage());
            $this->error('Vendor import failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->info("Done. Created: {$created}, Updated: {$updated}, Total processed: {$totalProcessed}");
        Log::info('Vendor import finished.', ['created' => $created, 'updated' => $updated, 'total' => $totalProcessed]);

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<int, mixed>|null
     */
    private function fetchBatch(array $headers, int $limit, int $offset): ?array
    {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $response = Http::withHeaders($headers)->get('https://api.propertyware.com/pw/api/rest/v1/vendors', [
                'includeCustomFields' => 'true',
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->serverError() && $attempt < $maxRetries) {
                Log::warning('PropertyWare 5xx error on vendors, retrying', [
                    'offset' => $offset,
                    'status' => $response->status(),
                    'attempt' => $attempt,
                ]);
                sleep(5);

                continue;
            }

            Log::error('Error retrieving vendors', [
                'status' => $response->status(),
                'body' => $response->body(),
                'offset' => $offset,
                'attempt' => $attempt,
            ]);

            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertVendor(array $data): ?string
    {
        $pwId = $data['id'] ?? null;

        if (! $pwId) {
            return null;
        }

        $name = $data['companyName'] ?? $data['name'] ?? null;
        $email = $data['email'] ?? $pwId.'@texasrenter.com';
        $phone = $data['phone'] ?? $data['otherPhone'] ?? null;
        $address = $this->joinAddress($data);

        // The password is set only when the user is first created: an
        // updateOrCreate that included it would silently reset the portal
        // password of every vendor on every run (and burn a bcrypt per vendor,
        // which makes a scheduled full walk infeasible).
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'name' => $name,
                'phone' => $phone,
                'address' => $address,
            ]);
        } else {
            $user = User::create([
                'email' => $email,
                'name' => $name,
                'phone' => $phone,
                'address' => $address,
                'password' => bcrypt($email),
            ]);
        }

        if (! $user->hasRole('vendor')) {
            $user->assignRole('vendor');
        }

        $exists = Vendor::where('propertyware_id', $pwId)->exists();

        Vendor::updateOrCreate(
            ['propertyware_id' => $pwId],
            [
                'name' => $name,
                'name_on_check' => $data['nameOnCheck'] ?? $name,
                'email' => $email,
                'account_number' => $data['accountNumber'] ?? null,
                'credit_limit' => $data['creditLimit'] ?? null,
                'payment_term_days_to_pay' => $data['paymentTermDaysToPay'] ?? null,
                'payment_terms' => $data['paymentTerms'] ?? null,
                'taxID' => $data['taxId'] ?? null,
                'vendor_type' => $data['type'] ?? null,
                'is_active' => $data['active'] ?? true,
                'user_id' => $user->id,
            ],
        );

        return $exists ? 'updated' : 'created';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function joinAddress(array $data): ?string
    {
        $parts = array_filter([
            $data['address'] ?? null,
            $data['address2'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['zip'] ?? null,
        ]);

        return empty($parts) ? null : implode(', ', $parts);
    }
}
