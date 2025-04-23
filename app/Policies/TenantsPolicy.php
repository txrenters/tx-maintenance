<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class TenantsPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function view_tenants(User $user): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
           ? Response::allow()
           : Response::deny('You do not have permission to view tenant page.');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view_tenant(User $user): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
           ? Response::allow()
           : Response::deny('You do not have permission to view tenant page.');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create_tenant(User $user): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
           ? Response::allow()
           : Response::deny('You do not have permission to view tenant page.');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update_tenant(User $user): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
           ? Response::allow()
           : Response::deny('You do not have permission to view tenant page.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete_tenant(User $user): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
           ? Response::allow()
           : Response::deny('You do not have permission to view tenant page.');
    }
}
