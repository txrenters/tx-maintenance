<?php

namespace Database\Seeders;

use App\Models\User;
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
        $now = now()->format('Y-m-d H:i:s');
        // Disable query logging and events for performance
        DB::disableQueryLog();
        DB::connection()->unsetEventDispatcher();
        User::flushEventListeners();
        // Read CSV file
        $csvFile = public_path('tenants.csv');

        if (! File::exists($csvFile)) {
            Log::error('CSV file not found: '.$csvFile);

            return;
        }

        $handle = fopen($csvFile, 'r');
        fgetcsv($handle); // Skip header row

        $chunkSize = 500;
        $chunks = [];

        try {
            $pdo = DB::connection()->getPdo();
            $stmt = $this->prepareChunkedStatementTenants($chunkSize);

            // Fetch existing propertyware IDs
            $existingTenants = DB::table('tenants')->pluck('propertyware_id')->toArray();
            $chunkedPropertywareIds = [];

            while (($tenant = fgetcsv($handle)) !== false) {

                $tenant_propertyware_id = $tenant[3] ?? null;

                if (empty($tenant_propertyware_id) || in_array($tenant_propertyware_id, $existingTenants) || in_array($tenant_propertyware_id, $chunkedPropertywareIds)) {  // 3rd column is propertyware_id (_id)
                    continue;  // Skip if propertyware_id is empty
                }

                $email = trim(strtolower($tenant[17] ?? ''));

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    // Create a fallback unique email using propertyware ID
                    $tenantEmail = $tenant_propertyware_id.'@texasrenter.com';
                } else {
                    $tenantEmail = $email;
                }

                $address = trim(implode(' ', array_filter([
                    $tenant[6] ?? null,
                    $tenant[7] ?? null,
                    $tenant[8] ?? null,
                    $tenant[9] ?? null,
                    $tenant[37] ?? null,
                ])));

                $address = preg_replace('/[^\x20-\x7E]/u', '', $address);
                $address = str_replace("\xC2\xA0", ' ', $address); // Replace non-breaking spaces

                $phone = $tenant[22];
                $phone = preg_replace('/[^\x20-\x7E]/u', '', $phone);
                $phone = str_replace("\xC2\xA0", ' ', $phone); // Replace non-breaking spaces

                $usersData = [
                    'email' => $tenantEmail,
                    'name' => trim(($tenant[20] ?? '').' '.($tenant[24] ?? '')),
                    'phone' => $tenant[22] ?? null,
                    'company' => $tenant[11] ?? null,
                    'address' => $address,
                    'password' => bcrypt($tenantEmail),
                ];

                $userId = $this->createOrUpdateUser($usersData);

                // Prepare tenant data for bulk insertion
                $chunks[] = [
                    $tenant[3] ?? null, // propertyware_id
                    $tenant[20] ?? null, // first_name
                    $tenant[25] ?? null, // middle_name
                    $tenant[24] ?? null, // last_name
                    $tenant[34] ?? null, // suffix
                    ! empty($tenant[8]) && $tenant[8] !== 'NULL' ? $tenant[8] : null, // birth_date
                    $tenant[21] == 2 ? 'Female' : 'Male', // gender
                    $tenantEmail, // email
                    $tenant[19] ?? null, // fax
                    $tenant[29] ?? null, // pager
                    $tenant[22] ?? null, // home_phone
                    $tenant[36] ?? null, // work_phone
                    $tenant[26] ?? null, // mobile_phone
                    $tenant[6] ?? null, // address
                    $tenant[7] ?? null, // address2
                    $tenant[9] ?? null, // city
                    $tenant[33] ?? null, // state
                    $tenant[14] ?? null, // country
                    $tenant[37] ?? null, // zip
                    $tenant[35] ?? null, // web_address
                    $tenant[23] ?? null, // job_title
                    $tenant[11] ?? null, // company
                    $tenant[32] ?? null, // ssn
                    $tenant[31] ?? null, // search_tag
                    $tenant[30] ?? null, // salutation
                    $tenant[27] ?? null, // name_on_check
                    1,
                    0,
                    $tenant[10] ?? null, // comments
                    $userId, // user_id
                    $now,
                    $now,
                ];

                $chunkedPropertywareIds[] = $tenant_propertyware_id;
                if (count($chunks) == $chunkSize) {
                    $stmt->execute(array_merge(...$chunks));
                    $chunks = [];
                    $chunkedPropertywareIds = [];

                }
            }

            // Insert remaining rows
            if (! empty($chunks)) {
                $remainingRows = count($chunks);
                $stmt = $this->prepareChunkedStatementTenants($remainingRows);
                $stmt->execute(array_merge(...$chunks));
            }
        } finally {
            fclose($handle);
        }
    }

    private function createOrUpdateUser(array $data): int
    {
        static $existingUsers = null;

        if ($existingUsers == null) {
            $existingUsers = User::pluck('id', 'email')->mapWithKeys(function ($id, $email) {
                return [strtolower($email) => $id];
            })->toArray();
        }

        $email = strtolower($data['email']);

        if (isset($existingUsers[$email])) {
            $userId = $existingUsers[$email];

            // You can optionally ensure the role is assigned (in case it was missed before)
            $user = User::find($userId);
            if (! $user->hasRole('tenant')) {
                $user->assignRole('tenant');
            }

        } else {
            $user = User::create($data);
            $user->assignRole('tenant'); // 🎯 Here’s the role being used
            $userId = $user->id;

            // 🔁 Update the cache
            $existingUsers[$email] = $userId;
        }

        return $userId;
    }

    private function prepareChunkedStatementTenants($chunkSize)
    {
        $rowPlaceholders = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $placeholders = implode(',', array_fill(0, $chunkSize, $rowPlaceholders));

        $query = DB::connection()->getPdo()->prepare('
            INSERT INTO tenants (
                propertyware_id, first_name, middle_name, last_name, suffix, birth_date, gender,
                email, fax, pager, home_phone, work_phone, mobile_phone, address, address2, city, state, country,
                zip, web_address, job_title, company, ssn, search_tag, salutation, name_on_check,
                is_name_on_lease, is_dirty, comments, user_id, created_at, updated_at
            ) VALUES '.$placeholders
        );

        return $query;
    }
}
