<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    protected $table = 'tasks';

    protected $guarded = [];

    public function taskTemplate()
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    public function nextServiceStatus()
    {
        return $this->belongsTo(ServiceStatus::class,'next_service_status_id');
    }

    public function scopeFilter($query, array $filter): void
    {
        if(!empty($filter['search'])){
            $search = $filter['search'];

            $query
            ->whereAny([
                'name',
                ], 'LIKE', "%{$search}%");
        }
    }

}
