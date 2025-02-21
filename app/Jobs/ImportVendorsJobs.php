<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportVendorsJobs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $data;

    /**
     * Create a new job instance.
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::beginTransaction();

        try {
            $vendorData = [];
            
            // Ensure these keys exist before accessing them
            $vendorId = !empty($this->data['ID']) ? (string) $this->data['ID'] : null;
            $vendorEmail = !empty($this->data['email']) && !empty($this->data['email']) ? $this->data['email'] : ($vendorId . '@renters.com');
    
            if (!$vendorId) {
                continue; // Skip this iteration if there's no valid vendorId
            }
    
            $checkUser = User::where('email', $vendorEmail)->select('id')->first();
            $existingVendor = Vendor::where('uuid', $vendorId)->lockForUpdate()->first();
    
            if (!$existingVendor) {
                // Only populate vendor data if necessary fields exist
                $vendorData[] = [
                    'propertyware_id' => $vendorId,
                    'name' => !empty($this->data['name']) ? (string) $this->data['name'] : null,
                    'name_on_check' => !empty($this->data['nameOnCheck']) ? (string) $this->data['nameOnCheck'] : null,
                    'account_number' => !empty($this->data['accountNumber']) ? (string) $this->data['accountNumber'] : null,
                    'credit_limit' => !empty($this->data['creditLimit']) ? (string) $this->data['creditLimit'] : null,
                    'payment_term_days_to_pay' => !empty($this->data['paymentTermDaysToPay']) ? (string) $this->data['paymentTermDaysToPay'] : null,
                    'payment_terms' => !empty($this->data['paymentTerms']) ? (string) $this->data['paymentTerms'] : null,
                    'taxID' => !empty($this->data['taxID']) ? (string) $this->data['taxID'] : null,
                    'vendor_type' =>  !empty($this->data['vendorType']) ? (string) $this->data['vendorType'] : null,
                    'twilio_number' => '',
                    'is_active' => !empty($this->data['active']) ? ($this->data['active'] == 'true' ? true : false) : null,
                    'user_id' => null, // Will be updated later
                ];
    
                // If the user does not exist, create a new user
                if (!$checkUser) {
                    $address = trim(implode(' ', [
                        !empty($this->data['address']) ? (string) $this->data['address'] : null,
                        !empty($this->data['address2']) ? (string) $this->data['address2'] : null,
                        !empty($this->data['city']) ? (string) $this->data['city'] : null,
                        !empty($this->data['state']) ? (string) $this->data['state'] : null,
                        !empty($this->data['country']) ? (string) $this->data['country'] : null,
                        !empty($this->data['zip']) ? (string) $this->data['zip'] : null,
                    ]));
    
                    $user = User::create([
                        'email' => $vendorEmail,
                        'name' => !empty($this->data['name']) ? (string) $this->data['name'] : null,
                        'phone' => !empty($this->data['phone']) ? (string) $this->data['phone'] : null,
                        'company' => !empty($this->data['companyName']) ? (string) $this->data['companyName'] : null,
                        'address' => $address,
                        'website' =>  !empty($this->data['website']) ? (string) $this->data['website'] : null,
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
            
            // Batch insert vendor data, associating users with vendors
            if (!empty($vendorData)) {
                // Vendor::insert($vendorData); 
                // Perform a batch insert to reduce queries
                foreach (array_chunk($vendorData, 100) as $chunk) {
                    Vendor::insert($chunk);
                }
            }

            DB::commit();

            Log::info('Vendor import completed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Vendor import failed: ' . $e->getMessage());
        }
    }
}
