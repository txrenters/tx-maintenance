<?php

namespace App\Policies;

use App\Models\Owner;
use App\Models\User;
use Illuminate\Support\Facades\Response;

class OwnerPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function view_owners(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to view owner page.');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view_owner(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to view owner page.');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create_owner(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to view owner page.');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update_owner(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to view owner page.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete_owner(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to view owner page.');
    }

}
