<?php

namespace App\Models;

use App\Models\Scopes\CalendarScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([CalendarScope::class])]
class ServiceSchedule extends Model
{
    protected $table = 'service_schedules';

    protected $guarded = [];

    public function work_order(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenants::class, 'tenant_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The in-house technician chosen when the schedule was set, so the
     * tenant's appointment text can attach the right face. Nullable and
     * unconstrained: a deleted technician simply means no photo goes out.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
