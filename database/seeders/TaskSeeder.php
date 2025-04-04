<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class TaskSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $json = File::get(public_path('tasks.json'));

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON format.');

            return;
        }

        $taskData = [];
        $now = now();

        foreach ($data as $template) {
            $taskData[] = [
                'id' => $template['id'],
                'name' => $template['name'],
                'is_mandatory' => $template['is_mandatory'],
                'due_date' => $template['due_date'],
                'is_optional' => $template['is_optional'],
                'is_emergency' => $template['is_emergency'],
                'type' => $template['type'],
                'next_service_status_id' => $template['next_service_status_id'],
                'task_template_id' => $template['task_template_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($taskData) {
            DB::table('tasks')->insert($taskData);
        }
    }
}
