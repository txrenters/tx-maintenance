<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\TaskTemplateFactory> */
    use HasFactory;

    protected $table = 'task_templates';

    protected $guarded = [];

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function currentServiceStatus(): BelongsTo
    {
        return $this->belongsTo(ServiceStatus::class, 'current_service_status_id');
    }

    public function nextServiceStatus(): BelongsTo
    {
        return $this->belongsTo(ServiceStatus::class, 'next_service_status_id');
    }

    public function scopeFilter($query, array $filter): void
    {
        if (! empty($filter['search'])) {
            $search = $filter['search'];

            $query
                ->whereAny([
                    'name',
                ], 'LIKE', "%{$search}%");
        }
    }
}
