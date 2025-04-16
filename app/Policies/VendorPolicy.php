<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Support\Facades\Response;

class VendorPolicy
{
    public function view_vendors(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to view vendor page.');
    }

    public function view_vendor(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to view vendor page.');
    }

    public function create_vendor(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to create vendor page.');
    }

    public function update_vendor(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to update vendor page.');
    }

    public function delete_vendor(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to delete vendor page.');
    }
}
