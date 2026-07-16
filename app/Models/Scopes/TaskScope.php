<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TaskScope implements Scope
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

        if ($user->hasRole('vendor')) {
            $builder->where('assigned_user_id', $user->id);
        }

        if ($user->hasRole('tenant')) {
            // A tenant only sees tasks on their own work orders; fail closed when
            // their account isn't linked to a tenant record.
            if ($user->tenant) {
                $builder->whereHas('work_order', fn ($q) => $q->where('tenant_id', $user->tenant->id));
            } else {
                $builder->whereRaw('1 = 0');
            }
        }
    }
}
