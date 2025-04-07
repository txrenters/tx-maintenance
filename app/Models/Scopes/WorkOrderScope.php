<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkOrderScope implements Scope
{
    // Cache the user and related data in static memory
    protected static ?User $cachedUser = null;

    public function apply(Builder $builder, Model $model): void
    {
        if (! auth()->check()) {
            return;
        }

        // Only hit the DB once per request
        $user = self::$cachedUser ??= User::with(['vendor', 'tenant', 'roles'])->find(auth()->id());

        if (! $user || $user->hasRole('admin')) {
            return;
        }

        // Apply role-based filters
        if ($user->hasRole('woc')) {
            $builder->where('user_id', $user->id);
        }

        if ($user->hasRole('vendor') && $user->vendor) {
            $builder->where(function ($query) use ($user) {
                $query->whereHas('vendors', fn ($q) => $q->where('vendor_id', $user->vendor->id))
                    ->orWhereHas('tasks', fn ($q) => $q->where('assigned_user_id', $user->id))
                    ->orWhereHas('attachments', fn ($q) => $q->where('user_id', $user->id));
            });
        }

        if ($user->hasRole('tenant') && $user->tenant) {
            $builder->where('tenant_id', $user->tenant->id);
        }
    }
}
