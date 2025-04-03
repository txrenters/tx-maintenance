<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class TenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $json = File::get(public_path('tenants.json'));
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON format.');
            return;
        }

        // Prepare user data for bulk insert/update
        $usersToInsert = [];
        $tenantsToInsert = [];
        $existingUserEmails = [];

        foreach (array_chunk($data, 100) as $tenantChunks) {
            foreach ($tenantChunks as $tenant) {
                $tenant_propertyware_id = $tenant['_id'];
                
                // Check if tenant already exists by propertyware_id
                $checkTenant = DB::table('tenants')->where('propertyware_id', $tenant_propertyware_id)->exists();
                if (!$checkTenant) {
                    $tenantEmail = $tenant['email'] ?? $tenant_propertyware_id . "@texasrenter.com";

                    // Prepare user data for bulk insert/update
                    if (!in_array($tenantEmail, $existingUserEmails)) {
                        $existingUserEmails[] = $tenantEmail;

                        $usersToInsert[] = [
                            'email' => $tenantEmail,
                            'name' => $tenant['firstName'] . ' ' . $tenant['lastName'],
                            'phone' => $tenant['homePhone'] ?? null,
                            'company' => $tenant['company'] ?? null,
                            'address' => trim(implode(' ', array_filter([
                                $tenant['address'] ?? null,
                                $tenant['address2'] ?? null,
                                $tenant['city'] ?? null,
                                $tenant['state'] ?? null,
                                $tenant['country'] ?? null,
                                $tenant['zip'] ?? null,
                            ]))),
                            'password' => bcrypt($tenantEmail),
                        ];
                    }

                    // Prepare tenant data for bulk insert
                    $tenantsToInsert[] = [
                        'propertyware_id' => $tenant_propertyware_id,
                        'first_name' => $tenant['firstName'] ?? null,
                        'middle_name' => $tenant['middleName'] ?? null,
                        'last_name' => $tenant['lastName'] ?? null,
                        'suffix' => $tenant['suffix'] ?? null,
                        'birth_date' => !empty($tenant['birthDate']) ? ($tenant['birthDate'] != 'NULL' ? $tenant['birthDate'] : null) : null,
                        'gender' => $tenant['gender'] == 1 ? 'Male' : "Female",
                        'email' => $tenantEmail,
                        'fax' => $tenant['fax'] ?? null,
                        'pager' => $tenant['pager'] ?? null,
                        'home_phone' => $tenant['homePhone'] ?? null,
                        'work_phone' => $tenant['workPhone'] ?? null,
                        'mobile_phone' => $tenant['mobilePhone'] ?? null,
                        'address' => $tenant['address'] ?? null,
                        'address2' => $tenant['address2'] ?? null,
                        'city' => $tenant['city'] ?? null,
                        'state' => $tenant['state'] ?? null,
                        'country' => $tenant['country'] ?? null,
                        'zip' => $tenant['zip'] ?? null,
                        'web_address' => $tenant['webAddress'] ?? null,
                        'job_title' => $tenant['jobTitle'] ?? null,
                        'company' => $tenant['company'] ?? null,
                        'ssn' => $tenant['ssn'] ?? null,
                        'search_tag' => $tenant['searchTag'] ?? null,
                        'salutation' => $tenant['salutation'] ?? null,
                        'name_on_check' => $tenant['nameOnCheck'] ?? null,
                        'is_name_on_lease' => false,
                        'is_dirty' => $tenant['dirty'] ?? null,
                        'comments' => $tenant['comments'] ?? null,
                        'user_id' => null, // Placeholder, we'll update user_id later
                    ];
                }
            }

            // Bulk insert users and get their IDs
            if (!empty($usersToInsert)) {
                $insertedUsers = DB::table('users')->insertGetId($usersToInsert);

                // Update the tenants with the corresponding user IDs
                foreach ($tenantsToInsert as $key => $tenant) {
                    $tenant['user_id'] = $insertedUsers[$key]->id;  // Update tenant with the user ID
                    $tenantsToInsert[$key] = $tenant;  // Reassign to updated tenant array
                }
            }

            // Bulk insert tenants
            if (!empty($tenantsToInsert)) {
                DB::table('tenants')->insert($tenantsToInsert);
            }
        }
    }
}
