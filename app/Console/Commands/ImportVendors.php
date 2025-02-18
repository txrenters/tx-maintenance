<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Vendor;
use App\Services\PropertyWareService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportVendors extends Command
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
    protected $description = 'Imports vendors from PropertyWare API every minute';

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
        DB::beginTransaction();

        try {
            $vendors = $this->propertyWareService->getVendors();

            $vendorData = [];

            foreach ($vendors as $keys => $vendor) {
                if ($keys >= 1) {
                    break;  // Exit the loop after the first iteration
                }
            
                foreach ($vendor as $vendor_values) {
                    // Convert SimpleXMLElement to array
                    $data = json_decode(json_encode($vendor_values), true);
            
                    // Ensure these keys exist before accessing them
                    $vendorId = !empty($data['ID']) ? (string) $data['ID'] : null;
                    $vendorEmail = !empty($data['email']) && !empty($data['email']) ? $data['email'] : ($vendorId . '@renters.com');
            
                    if (!$vendorId) {
                        continue; // Skip this iteration if there's no valid vendorId
                    }
            
                    $checkUser = User::where('email', $vendorEmail)->first();
                    $existingVendor = Vendor::where('uuid', $vendorId)->first();
            
                    if (!$existingVendor) {
                        // Only populate vendor data if necessary fields exist
                        $vendorData[] = [
                            'uuid' => $vendorId,
                            'name' => !empty($data['name']) ? (string) $data['name'] : null,
                            'name_on_check' => !empty($data['nameOnCheck']) ? (string) $data['nameOnCheck'] : null,
                            'account_number' => !empty($data['accountNumber']) ? (string) $data['accountNumber'] : null,
                            'credit_limit' => !empty($data['creditLimit']) ? (string) $data['creditLimit'] : null,
                            'payment_term_days_to_pay' => !empty($data['paymentTermDaysToPay']) ? (string) $data['paymentTermDaysToPay'] : null,
                            'payment_terms' => !empty($data['paymentTerms']) ? (string) $data['paymentTerms'] : null,
                            'taxID' => !empty($data['taxID']) ? (string) $data['taxID'] : null,
                            'vendor_type' =>  !empty($data['vendorType']) ? (string) $data['vendorType'] : null,
                            'twilio_number' => '',
                            'is_active' => !empty($data['active']) ? ($data['active'] == 'true' ? true : false) : null,
                            'user_id' => null, // Will be updated later
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
            
                        // If the user does not exist, create a new user
                        if (!$checkUser) {
                            $address = trim(implode(' ', [
                                !empty($data['address']) ? (string) $data['address'] : null,
                                !empty($data['address2']) ? (string) $data['address2'] : null,
                                !empty($data['city']) ? (string) $data['city'] : null,
                                !empty($data['state']) ? (string) $data['state'] : null,
                                !empty($data['country']) ? (string) $data['country'] : null,
                                !empty($data['zip']) ? (string) $data['zip'] : null,
                            ]));
            
                            $user = User::create([
                                'email' => $vendorEmail,
                                'name' => !empty($data['name']) ? (string) $data['name'] : null,
                                'phone' => !empty($data['phone']) ? (string) $data['phone'] : null,
                                'company' => !empty($data['companyName']) ? (string) $data['companyName'] : null,
                                'address' => $address,
                                'website' =>  !empty($data['website']) ? (string) $data['website'] : null,
                                'password' => bcrypt($vendorEmail), // Set email as default password
                            ]);
            
                            $user->assignRole('vendor');
            
                            // Update vendorData to include the user_id for this vendor
                            $vendorData[count($vendorData) - 1]['user_id'] = $user->id;
                        } else {
                            // If user exists, assign the existing user's ID to vendor
                            $vendorData[count($vendorData) - 1]['user_id'] = $checkUser->id;
                        }
                    }
                }
            }
            
            // Batch insert vendor data, associating users with vendors
            if (!empty($vendorData)) {
                Vendor::insert($vendorData); // Perform a batch insert to reduce queries
            }

            DB::commit();

            Log::info('Vendor import completed successfully.');
            $this->info('Vendor import completed successfully.');

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Vendor import failed: ' . $e->getMessage());
            $this->error('Vendor import failed: ' . $e->getMessage());
        }
    }
}
