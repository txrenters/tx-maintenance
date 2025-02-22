<?php

namespace App\Policies;

use App\Models\ServiceStatus;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ServiceStatusPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function view_status(User $user): Response
    {
        return $user->hasRole('admin')
            ? Response::allow()
            : Response::deny('You do not have permission to view service statuses.');
    }
    /**
     * Determine whether the user can create models.
     */
    public function create_status(User $user): Response
    {
        return $user->hasRole('admin')
        ? Response::allow()
        : Response::deny('You do not have permission to view service statuses.');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update_status(User $user): Response
    {
        return $user->hasRole('admin')
        ? Response::allow()
        : Response::deny('You do not have permission to view service statuses.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete_status(User $user): Response
    {
        return $user->hasRole('admin')
        ? Response::allow()
        : Response::deny('You do not have permission to view service statuses.');
    }
}
