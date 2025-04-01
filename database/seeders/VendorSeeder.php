<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $json  = File::get(public_path('vendor_types.json'));

        $data = json_decode($json , true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON format.');
            return;
        }

        $vendorTypes = [];

        foreach($data as $types){
            $vendorTypes[] = [
                'name' => $types['title'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if($vendorTypes){
            DB::table('vendor_types')->insert($vendorTypes);
        }


        $json  = File::get(public_path('vendors.json'));

        $data = json_decode($json , true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON format.');
            return;
        }

        foreach($data as $vendor){

            $vendorId = $vendor['_id'] ?? null;
            $vendorEmail = empty($vendor['email']) ? ($vendorId . '@txrenters.com') : $vendor['email'];

            $existingVendor = DB::table('vendors')->where('propertyware_id', $vendorId)->exists();

            if (!$existingVendor && !empty($vendorId)) {

                $address = trim(implode(' ', array_filter([
                    $vendor['address'] ?? null,
                    $vendor['address2'] ?? null,
                    $vendor['city'] ?? null,
                    $vendor['state'] ?? null,
                    $vendor['country'] ?? null,
                    $vendor['zip'] ?? null,
                ])));

                // Create or update the user
                $usersData = [
                    'email' => $vendorEmail,
                    'name' => $vendor['name'] ?? $vendor['nameOnCheck'],
                    'phone' => $vendor['phone'] ?? null,
                    'company' => $vendor['companyName'] ?? null,
                    'address' => $address,
                    'website' => $vendor['website'] ?? null,
                    'password' => bcrypt($vendorEmail), // Default password as email
                ];

                $user = User::updateOrCreate(
                    ['email' => $vendorEmail],
                    $usersData
                );

                // 🚨 Add check before accessing $user->id
                if (!$user) {
                    throw new \Exception("User creation failed for email: $vendorEmail");
                }

                $user->assignRole('vendor'); // Assign 'vendor' role

                $vendorsData = [
                    'propertyware_id' => $vendorId,
                    'name' => $vendor['name'] ?? $vendor['nameOnCheck'],
                    'email' => $vendorEmail,
                    'name_on_check' => $vendor['nameOnCheck'] ?? null,
                    'vendor_type' => DB::table('vendor_types')->where('id', $vendor['vendorTypeId'] ?? '')->value('name'),
                    'twilio_number' => '',
                    'is_active' => false,
                    'user_id' => $user->id, // Make sure $user is not null
                    'created_at' =>  $now,
                    'updated_at' =>  $now,
                ];

                DB::table('vendors')->updateOrInsert(
                    ['propertyware_id' => $vendorId],
                    $vendorsData
                );
            }
        }
    }
}
