<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vendor;
use App\Services\PropertyWareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorsImpotyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vendors:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import vendors from PropertyWare API every minute';

    protected PropertyWareService $propertyWareService;

    public function __construct(PropertyWareService $propertyWareService)
    {
        parent::__construct();
        $this->propertyWareService = $propertyWareService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $vendors = $this->propertyWareService->getVendors();
        $now = now()->format('Y-m-d H:i:s');

        try {
            DB::beginTransaction();

            $vendorEmails = array_map(fn($vendor) => (string) ($vendor['email'] ?? null) . '@renters.com', $vendors);
            $vendorIds = array_map(fn($vendor) => (string) ($vendor['ID'] ?? null), $vendors);

            // Fetch existing users and vendors in one go
            $existingUsers = User::whereIn('email', $vendorEmails)
                ->pluck('id', 'email')
                ->toArray();

            $existingVendors = Vendor::whereIn('propertyware_id', $vendorIds)
                ->pluck('propertyware_id')
                ->toArray();

            $usersData = [];
            $vendorsData = [];

            foreach ($vendors as $vendor) {
                foreach ($vendor as $vendor_values) {
                    $data = json_decode(json_encode($vendor_values), true);

                    $vendorId = $data['ID'] ?? null;
                    $vendorEmail = $data['email'] ?? ($vendorId . '@renters.com');

                    if (!$vendorId || in_array($vendorId, $existingVendors)) {
                        continue; // Skip if vendor exists
                    }

                    if (!isset($existingUsers[$vendorEmail])) {
                        // Prepare user data
                        $address = trim(implode(' ', array_filter([
                            $data['address'] ?? null,
                            $data['address2'] ?? null,
                            $data['city'] ?? null,
                            $data['state'] ?? null,
                            $data['country'] ?? null,
                            $data['zip'] ?? null,
                        ])));

                        $usersData[] = [
                            'email' => $vendorEmail,
                            'name' => $data['name'] ?? null,
                            'phone' => $data['phone'] ?? null,
                            'company' => $data['companyName'] ?? null,
                            'address' => $address,
                            'website' => $data['website'] ?? null,
                            'password' => bcrypt($vendorEmail), // Default password as email
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    $vendorsData[] = [
                        'propertyware_id' => $vendorId,
                        'name' => $data['name'] ?? null,
                        'email' => $vendorEmail,
                        'name_on_check' => $data['nameOnCheck'] ?? null,
                        'account_number' => $data['accountNumber'] ?? null,
                        'credit_limit' => $data['creditLimit'] ?? null,
                        'payment_term_days_to_pay' => $data['paymentTermDaysToPay'] ?? null,
                        'payment_terms' => $data['paymentTerms'] ?? null,
                        'taxID' => $data['taxID'] ?? null,
                        'vendor_type' => $data['vendorType'] ?? null,
                        'twilio_number' => '',
                        'is_active' => isset($data['active']) && $data['active'] === 'true',
                        'user_id' => null, // Will be updated later
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Bulk insert users
            if (!empty($usersData)) {
                DB::table('users')->insertOrIgnore($usersData);
            }

            // Refresh user IDs
            $newUsers = User::whereIn('email', array_column($usersData, 'email'))
                ->pluck('id', 'email')
                ->toArray();

            // Assign role to all newly inserted users in one go
            $usersWithRole = User::whereIn('id', $newUsers)->get();
            foreach ($usersWithRole as $user) {
                $user->assignRole('vendor'); // Assign 'vendor' role
            }

            // Update vendorsData with correct user_id
            foreach ($vendorsData as &$vendor) {
                $vendor['user_id'] = $newUsers[$vendor['email']] ?? null;
            }

            // Bulk insert vendors
            if (!empty($vendorsData)) {
                DB::table('vendors')->insertOrIgnore($vendorsData);
            }

            DB::commit();
            Log::info('Vendor import completed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Vendor import failed: ' . $e->getMessage());
        }
    }


    
}
