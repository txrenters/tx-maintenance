<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskDetail extends Model
{
    protected $table = 'task_details';

    protected $guarded = [];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function taskServiceStatus()
    {
        return $this->belongsTo(ServiceStatus::class,'task_service_status_id');
    }
}
