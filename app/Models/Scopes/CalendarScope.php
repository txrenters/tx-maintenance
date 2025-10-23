<?php

namespace App\Models\Scopes;

use App\Models\Owner;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CalendarScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = User::with(['vendor', 'tenant'])->find(auth()->id());

        if (! $user) {
            return;
        }

        if ($user->hasRole('owner') && $user->owner) {
            $email = strtolower($user->owner->email);

            // Find all owners with the same email as the logged-in user's owner record
            $ownerIds = Owner::whereRaw('LOWER(email) = ?', [$email])->pluck('id');

            // Filter work orders linked to any of those owner IDs
            $workOrderIds = WorkOrder::whereHas('owners', function ($q) use ($ownerIds) {
                $q->whereIn('owners.id', $ownerIds);
            })->pluck('id');

            $builder->whereHas('work_order', function ($q) use ($workOrderIds) {
                $q->whereIn('work_order_id', $workOrderIds);
            });
        }

        if ($user->hasRole('vendor') && $user->vendor) {
            $builder->where('vendor_id', $user->vendor->id);
        }

        if ($user->hasRole('tenant') && $user->tenant) {
            $builder->where('tenant_id', $user->tenant->id);
        }
    }
}
