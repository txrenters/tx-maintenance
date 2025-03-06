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
        $vendors = collect($this->data)->toArray();
        $now = now()->format('Y-m-d H:i:s');

        try {
            DB::beginTransaction();

            $vendors = json_decode(json_encode($vendors), true);

            foreach ($vendors as $vendor) {
                $data = (array)$vendor;

                $vendorId = $data['ID'] ?? null;
                $vendorEmail = empty($data['email']) ? ($vendorId . '@renters.com') : $data['email'];

                $existingVendor = Vendor::where('propertyware_id', $vendorId)->first();

                if (!$existingVendor) {
                    $address = trim(implode(' ', array_filter([
                        $data['address'] ?? null,
                        $data['address2'] ?? null,
                        $data['city'] ?? null,
                        $data['state'] ?? null,
                        $data['country'] ?? null,
                        $data['zip'] ?? null,
                    ])));                   

                    $usersData = [
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

                    $user = User::create($usersData);
                    $user->assignRole('vendor'); // Assign 'vendor' role

                    $vendorsData = [
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
                        'is_active' => isset($data['active']) && $data['active'] == 'true' ? true : false,
                        'user_id' => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    Vendor::create($vendorsData);
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
