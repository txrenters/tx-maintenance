<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Owner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
class ImportOwnersJob implements ShouldQueue
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
        $owners = collect($this->data)->toArray();
        $now = now()->format('Y-m-d H:i:s');

        try {
            DB::beginTransaction();

            $owners = json_decode(json_encode($owners), true);
            $ownerData = [];

            foreach ($owners as $owner) {
                $data = (array)$owner;

                $existingOwner = Owner::where('propertyware_id', $data['ID'])->first();

                if (!$existingOwner) {

                    $address = trim(implode(' ', array_filter([
                        $data['address'] ?? null,
                        $data['address2'] ?? null,
                        $data['city'] ?? null,
                        $data['state'] ?? null,
                        $data['country'] ?? null,
                        $data['zip'] ?? null,
                    ])));                   
    
                    $usersData = [
                        'email' => $data['email'] ?? null,
                        'name' => $data['name'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'company' => $data['companyName'] ?? null,
                        'address' => $address,
                        'website' => $data['website'] ?? null,
                        'password' => bcrypt($data['email']), // Default password as email
                    ];
    
                    $user = User::updateOrCreate(['email' => $data['email']],$usersData);
                    $user->assignRole('owner'); // Assign 'owner' role

                    $ownerData[] = [
                        'propertyware_id' => $data['ID'] ?? null,
                        'client_data' => isset($data['clientData']) ? json_encode($data['clientData']) : null,
                        'contact_id' => $data['contactId'] ?? null,
                        'name' => $data['name'] ?? null,
                        'name_on_check' => $data['nameOnCheck'] ?? null,
                        'first_name' => $data['firstName'] ?? null,
                        'last_name' => $data['lastName'] ?? null,
                        'email' => $data['email'] ?? null,
                        'mobile' => isset($data['mobile']) ? (string) $data['mobile'] : null,
                        'phone' => isset($data['phone']) ? (is_array($data['phone']) ? json_encode($data['phone']) : (string) $data['phone']) : null,
                        'home_phone' => isset($data['homePhone']) ? (is_array($data['homePhone']) ? json_encode($data['homePhone']) : (string) $data['homePhone']) : null,
                        'work_phone' => isset($data['workPhone']) ? (is_array($data['workPhone']) ? json_encode($data['workPhone']) : (string) $data['workPhone']) : null,
                        'work_telephone' => isset($data['workTelePhone']) ? (string) $data['workTelePhone'] : null,
                        'address' => $data['address'] ?? null,
                        'address2' => $data['address2'] ?? null,
                        'city' => $data['city'] ?? null,
                        'state' => $data['state'] ?? null,
                        'zip' => $data['zip'] ?? null,
                        'country' => $data['country'] ?? null,
                        'company' => $data['companyName'] ?? null,
                        'tax_id' => isset($data['taxId']) ? (string) $data['taxId'] : null,
                        'percentage_ownership' => isset($data['percentageOwnership']) ? (float) $data['percentageOwnership'] : null,
                        'notes' => isset($data['notes']) ? json_encode($data['notes']) : null,
                        'user_id' => $user->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (!empty($ownerData)) {
                Owner::insert($ownerData);
            }

            DB::commit();
            Log::info('Owners imported successfully.');
        } catch (\Throwable $th) {
            DB::rollBack();
            Log::error('Failed to begin transaction: ' . $th->getMessage());
        }
    }
}
