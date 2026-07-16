<?php

namespace App\Models\Scopes;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkOrderScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        // Use the guard's user: the auth guard already caches the instance per
        // request, and loadMissing() only queries relations the first time.
        // (An earlier static cache here kept the FIRST authenticated user for
        // the whole PHP process, leaking one user's visibility onto another in
        // queue workers and tests.)
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $user->loadMissing(['vendor', 'tenant', 'roles']);

        if ($user->hasRole('admin') || $user->hasRole('woc') || $user->hasRole('accounting')) {
            return;
        }

        if ($user->hasRole('vendor') && $user->vendor) {
            $vendorId = $user->vendor->id;

            $builder->where(function ($query) use ($user, $vendorId) {
                $query->whereHas('vendors', fn ($q) => $q->where('vendor_id', $vendorId))
                    ->orWhereHas('tasks', fn ($q) => $q->where('assigned_user_id', $user->id))
                    ->orWhereHas('attachments', fn ($q) => $q->where('user_id', $user->id));
            });
        }

        if ($user->hasRole('owner') && $user->owner) {
            $email = strtolower($user->owner->email);

            // Find all owners with the same email as the logged-in user's owner record
            $ownerIds = Owner::whereRaw('LOWER(email) = ?', [$email])->pluck('id');

            // Filter work orders linked to any of those owner IDs
            $builder->whereHas('owners', function ($q) use ($ownerIds) {
                $q->whereIn('owners.id', $ownerIds);
            });
        }

        if ($user->hasRole('tenant')) {
            // Fail CLOSED: a tenant with no linked tenant record sees nothing,
            // never the whole company. Only their own work orders otherwise.
            if ($user->tenant) {
                $builder->where('tenant_id', $user->tenant->id);
            } else {
                $builder->whereRaw('1 = 0');
            }
        }
    }
}
