<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class TaskTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $json = File::get(database_path('seeders/data/task_templates.json'));

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON format.');

            return;
        }

        $templateData = [];
        $now = now();

        foreach ($data as $template) {
            $templateData[] = [
                'id' => $template['id'],
                'name' => $template['name'],
                'is_available' => $template['is_available'],
                'is_default' => $template['is_default'],
                'current_service_status_id' => $template['current_service_status_id'],
                'is_current_service_status_emergency' => $template['is_current_service_status_emergency'],
                'next_service_status_id' => $template['next_service_status_id'],
                'is_next_service_status_emergency' => $template['is_next_service_status_emergency'],
                'description' => $template['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($templateData) {
            DB::table('task_templates')->insert($templateData);
        }

    }
}
