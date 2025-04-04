<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class WOCNumbersPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function view_woc_user(User $user): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to view WOC Numbers.');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create_woc_user(User $user): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
             ? Response::allow()
             : Response::deny('You do not have permission to view WOC Numbers.');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update_woc_user(User $user): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to view WOC Numbers.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete_woc_user(User $user): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to view WOC Numbers.');
    }
}
