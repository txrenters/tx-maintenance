<?php

use App\Models\TaskTemplate;
use Database\Seeders\TurnoverTaskTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Seed the streamlined Turnover task templates on deploy. The seeder is
     * idempotent (it replaces any existing Turnover templates) and aborts
     * gracefully when required service statuses are missing (fresh installs
     * seed via DatabaseSeeder instead).
     */
    public function up(): void
    {
        (new TurnoverTaskTemplateSeeder)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        TaskTemplate::where('work_order_type', 'Turnover')->delete();
    }
};
