<?php

namespace App\Models;

use App\Models\Scopes\CalendarScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
     * The in-house technicians chosen when the schedule was set (THMP
     * sometimes sends two), so the tenant's appointment text can name them
     * and attach the right faces. Unconstrained: a deleted technician simply
     * drops out of the list.
     */
    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(Technician::class, 'service_schedule_technicians', 'service_schedule_id', 'technician_id')
            ->withTimestamps()
            ->orderBy('technicians.name');
    }

    /**
     * Record the chosen technicians. The older single technician_id column
     * mirrors the first pick so a code revert still knows who is going.
     *
     * @param  list<int>  $technicianIds
     */
    public function setTechnicians(array $technicianIds): void
    {
        $technicianIds = array_values(array_unique(array_map('intval', $technicianIds)));

        $this->technicians()->sync($technicianIds);
        $this->forceFill(['technician_id' => $technicianIds[0] ?? null])->save();
        $this->unsetRelation('technicians');
    }
}
