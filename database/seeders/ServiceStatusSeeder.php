<?php

namespace Database\Seeders;

use App\Models\ServiceStatus;
use Illuminate\Database\Seeder;

class ServiceStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            [
                'name' => 'New',
                'description' => 'The service request is new.',
            ],
            [
                'name' => 'Assigned - Waiting on Scheduling',
                'description' => 'The service request is assigned and waiting on scheduling.',
            ],
            [
                'name' => 'Assigned - Waiting on Owner Approval',
                'description' => 'The service request assigned and waiting on owner approval.',
            ],
            [
                'name' => 'Scheduled',
                'description' => 'The service request is scheduled.',
            ],
            [
                'name' => 'Service Completed - Call Tenant for Followup',
                'description' => 'The service request is completed and the tenant needs to be called for followup.',
            ],
            [
                'name' => 'Completed - Verified - Updating Owner',
                'description' => 'The service request is completed and the owner needs to be updated.',
            ],
            [
                'name' => 'Completed - Verified - Waiting on Bill',
                'description' => 'The service request is completed and waiting on the bill.',
            ],
            [
                'name' => 'Owner Completing Work',
                'description' => 'The service request is owned by the owner and the work is being completed.',
            ],
            [
                'name' => 'Waiting on Approved Applicant',
                'description' => 'The service request is waiting on an approved applicant.',
            ],
            [
                'name' => 'Owner Approved - Waiting on Scheduling',
                'description' => 'The service request is approved by the owner and waiting on scheduling.',
            ],
            [
                'name' => 'Waiting on Parts',
                'description' => 'The service request is waiting on parts.',
            ],
            [
                'name' => 'Waiting on Estimate',
                'description' => 'The service request is waiting on an estimate.',
            ],
            [
                'name' => 'Bill Attached - Waiting on Approval',
                'description' => 'The service request is waiting on bill approval.',
            ],
            [
                'name' => 'Approved - Waiting on Payment',
                'description' => 'The service request is approved and waiting on payment.',
            ],
            [
                'name' => 'Do not Pay, Waiting on Pics',
                'description' => 'The service request is waiting on pictures.',
            ],
            [
                'name' => 'Checking for Tenant Easy Fix',
                'description' => 'The service request is checking for an easy fix.',
            ],
            [
                'name' => 'Closed',
                'description' => 'The service request is closed.',
            ],
            [
                'name' => 'Not Changed',
                'description' => 'The service request is not changed.',
            ],
            [
                'name' => 'Waiting on Photos From Tenant',
                'description' => 'for tenants.',
            ],
            [
                'name' => 'Waiting on Approved Vendor',
                'description' => 'for vendor.',
            ],
            [
                'name' => 'Waiting Tenants Decision - Non Real Property Item',
                'description' => 'for tenants.',
            ],
        ];

        ServiceStatus::insert($statuses);
    }
}
