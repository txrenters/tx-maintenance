<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class TenantSeeder_copy extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Read CSV file
        $csvFile = public_path('tenants.csv');

        if (!File::exists($csvFile)) {
            Log::error('CSV file not found.');
            return;
        }

        $file = fopen($csvFile, 'r');

        // Skip the first row (header)
        fgetcsv($file);

        $existingTenants = DB::table('tenants')->pluck('propertyware_id')->toArray(); // Fetch existing propertyware_ids once
        $tenantsToInsert = [];
        $chunkedPropertywareIds = [];

        while (($tenant = fgetcsv($file)) !== false) { // Process CSV rows

            if (empty($tenant[3]) || in_array($tenant[3], $existingTenants) || in_array($tenant[3], $chunkedPropertywareIds)) {  // 3rd column is propertyware_id (_id)
                continue;  // Skip if propertyware_id is empty
            }

            // Constructing tenant data
            $tenant_propertyware_id = $tenant[3];  // _id
            $tenantEmail = $tenant[17] ?? $tenant_propertyware_id . "@texasrenter.com";  // email

            $address = trim(implode(' ', array_filter([
                $tenant[6] ?? null,  // address
                $tenant[7] ?? null,  // address2
                $tenant[9] ?? null,  // city
                $tenant[10] ?? null, // comments (not part of address)
                $tenant[32] ?? null, // zip
            ])));

            $usersData = [
                'email' => $tenantEmail,
                'name' => $tenant[20] . ' ' . $tenant[22],  // firstName + lastName
                'phone' => $tenant[21] ?? null,  // homePhone
                'company' => $tenant[11] ?? null, // company
                'address' => $address,
                'password' => bcrypt($tenantEmail),
            ];

            // Creating or updating user in bulk
            $user = $this->createOrUpdateUser($usersData, 'tenant');

            $tenantData = [
                'propertyware_id' => $tenant_propertyware_id,
                'first_name' => $tenant[20] ?? null,
                'middle_name' => $tenant[23] ?? null, // middleName
                'last_name' => $tenant[22] ?? null,
                'suffix' => $tenant[33] ?? null, // suffix
                'birth_date' => !empty($tenant[8]) ? ($tenant[8] != 'NULL' ? $tenant[8] : null) : null, // birthDate
                'gender' => $tenant[18] == 2 ? 'Female' : 'Male', // gender (assuming 2 is Female)
                'email' => $tenantEmail,
                'fax' => $tenant[19] ?? null,
                'pager' => $tenant[26] ?? null,
                'home_phone' => $tenant[21] ?? null,
                'work_phone' => $tenant[34] ?? null,
                'mobile_phone' => $tenant[25] ?? null,
                'address' => $tenant[6] ?? null,
                'address2' => $tenant[7] ?? null,
                'city' => $tenant[9] ?? null,
                'state' => $tenant[32] ?? null, // state
                'country' => $tenant[13] ?? null, // country
                'zip' => $tenant[35] ?? null, // zip
                'web_address' => $tenant[31] ?? null, // webAddress
                'job_title' => $tenant[24] ?? null, // jobTitle
                'company' => $tenant[11] ?? null,
                'ssn' => $tenant[28] ?? null, // ssn
                'search_tag' => $tenant[29] ?? null, // searchTag
                'salutation' => $tenant[27] ?? null, // salutation
                'name_on_check' => $tenant[30] ?? null, // nameOnCheck
                'is_name_on_lease' => false,
                'is_dirty' => false, // dirty
                'comments' => $tenant[10] ?? null, // comments
                'user_id' => $user->id,
            ];

            $chunkedPropertywareIds[] = $tenant_propertyware_id;
            $tenantsToInsert[] = $tenantData;

            // Batch insert every 100 records
            if (count($tenantsToInsert) >= 100) {
                DB::table('tenants')->insert($tenantsToInsert);
                $tenantsToInsert = []; // Reset array after inserting
                $chunkedPropertywareIds = [];

            }
        }

        // Insert remaining tenants
        if (!empty($tenantsToInsert)) {
            DB::table('tenants')->insert($tenantsToInsert);
        }

        fclose($file);
    }

    private function createOrUpdateUser(array $data, string $role): User
    {
        // Check if user exists, if not create new one
        $user = User::updateOrCreate(['email' => $data['email']], $data);
        $user->assignRole($role);
        return $user;
    }
}
