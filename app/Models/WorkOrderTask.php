<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrderTask extends Model
{
    use SoftDeletes;

    protected $table = 'work_order_tasks';

    protected $guarded = [];

    protected $dates = ['deleted_at']; // Optional but recommended

    public function assigned_user():BelongsTo
    {
        return $this->belongsTo(User::class,'assigned_user_id');
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
