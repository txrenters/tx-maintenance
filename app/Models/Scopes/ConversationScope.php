<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ConversationScope implements Scope
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

        if ($user->hasRole('woc')) {
            $builder->whereHas('work_order', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            });
        }

        if ($user->hasRole('vendor') && $user->vendor) {
            $builder->whereHas('work_order.vendors', function ($query) use ($user) {
                $query->whereHas('vendors', function ($q) use ($user) {
                    $q->where('vendor_id', $user->vendor->id);
                });
            });
        }

        if ($user->hasRole('tenant') && $user->tenant) {
            $builder->whereHas('work_order', function ($query) use ($user) {
                $query->where('tenant_id', $user->tenant->id);
            });
        }
    }
}
