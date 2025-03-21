<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class WorkOrderScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = User::with(['vendor','tenant'])->find(auth()->id());

        if (!$user) {
            return;
        }
    
        if($user->hasRole('woc')){
            $builder->where('user_id', $user->id);
        }

        if ($user->hasRole('vendor') && $user->vendor) {
            $builder->where(function ($query) use ($user) {
                $query->whereHas('vendors', function ($q) use ($user) {
                    $q->where('vendor_id', $user->vendor->id);
                })->orWhereHas('tasks', function ($q) use ($user) {
                    $q->where('assigned_user_id', $user->id);
                })->orWhereHas('attachments', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                });
            });
        }
        if($user->hasRole('tenant') && $user->tenant){
            $builder->where('tenant_id', $user->tenant->id);
        }
    }
}
