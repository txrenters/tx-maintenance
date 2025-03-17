<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    protected $table = 'tasks';

    protected $guarded = [];

    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    public function nextServiceStatus(): BelongsTo
    {
        return $this->belongsTo(ServiceStatus::class,'next_service_status_id');
    }

    public function taskDetails(): HasMany
    {
        return $this->hasMany(TaskDetail::class, 'task_id')->latest('created_at');
    }

    public function taskDetailYesOption(): HasOne
    {
        return $this->hasOne(TaskDetail::class, 'task_id')->where('task_for', 'Yes');
    }

    public function taskDetailNoOption(): HasOne
    {
        return $this->hasOne(TaskDetail::class, 'task_id')->where('task_for', 'No');
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
