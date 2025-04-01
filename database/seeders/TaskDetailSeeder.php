<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class TaskDetailSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $json  = File::get(public_path('task_details.json'));

        $data = json_decode($json , true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Invalid JSON format.');
            return;
        }

        $taskDetailData = [];
        $now = now();

        foreach($data as $template){
            $taskDetailData[] = [
                'id' => $template['id'],
                'task_for' => $template['task_for'],
                'is_task_service_status_emergency' => $template['is_task_service_status_emergency'],
                'task_service_status_id' => $template['task_service_status_id'],
                'task_id' => $template['task_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if($taskDetailData){
            DB::table('task_details')->insert($taskDetailData);
        }
    }
}
