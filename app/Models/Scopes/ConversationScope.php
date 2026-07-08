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

        // Staff (admin/woc/accounting) have full conversation access, matching
        // ConversationController::assertCanViewConversation. Only external parties
        // — vendors and tenants — are scoped to their own threads/work orders.
        if ($user->hasAnyRole(['admin', 'woc', 'accounting'])) {
            return;
        }

        if ($user->hasRole('vendor') && $user->vendor) {
            // Limit to work orders this vendor is assigned to, then to this
            // vendor's own thread so they never see another vendor's messages.
            $builder->whereHas('work_order.vendors', function ($query) use ($user) {
                $query->where('vendors.id', $user->vendor->id);
            })->forVendorThread($user->vendor);
        }

        if ($user->hasRole('tenant') && $user->tenant) {
            $builder->whereHas('work_order', function ($query) use ($user) {
                $query->where('tenant_id', $user->tenant->id);
            });
        }
    }
}
