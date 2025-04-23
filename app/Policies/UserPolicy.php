<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view_user(User $user): Response
    {
        return $user->hasRole('admin') && $user->hasPermissionTo('view')
            ? Response::allow()
            : Response::deny('You do not have permission to view users.');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create_user(User $user): Response
    {
        return $user->hasRole('admin') && $user->hasPermissionTo('create')
            ? Response::allow()
            : Response::deny('You do not have permission to view users.');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update_user(User $user): Response
    {
        return $user->hasRole('admin') && $user->hasPermissionTo('edit')
            ? Response::allow()
            : Response::deny('You do not have permission to view users.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete_user(User $user): Response
    {
        return $user->hasRole('admin') && $user->hasPermissionTo('delete')
        ? Response::allow()
        : Response::deny('You do not have permission to view users.');
    }
}
