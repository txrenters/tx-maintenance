<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(UserSeeder::class);
        $this->call(ServiceStatusSeeder::class);
        $this->call(WorkOrderCategorySeeder::class);
        $this->call(TaskTemplateSeeder::class);
        $this->call(TaskSeeder::class);
        $this->call(TaskDetailSeeder::class);
        // $this->call(VendorSeeder::class);
        // $this->call(TenantSeeder::class);
        // $this->call(OwnerSeeder::class);
    }
}
