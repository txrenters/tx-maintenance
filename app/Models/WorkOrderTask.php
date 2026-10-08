<?php

namespace App\Models;

use App\Models\Scopes\TaskScope;
use Database\Factories\WorkOrderTaskFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ScopedBy([TaskScope::class])]
class WorkOrderTask extends Model
{
    /** @use HasFactory<WorkOrderTaskFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'work_order_tasks';

    protected $guarded = [];

    protected $dates = ['deleted_at']; // Optional but recommended

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['auto_completed_at' => 'datetime'];
    }

    public function assigned_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
