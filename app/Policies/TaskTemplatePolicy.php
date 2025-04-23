<?php

namespace App\Policies;

use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TaskTemplatePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function view_task_template(User $user): Response
    {
        return $user->hasRole('admin')
            ? Response::allow()
            : Response::deny('You do not have permission to view Task Templates.');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view_template(User $user): Response
    {
        return $user->hasRole('admin')
        ? Response::allow()
        : Response::deny('You do not have permission to view Task Templates.');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create_template(User $user): Response
    {
        return $user->hasRole('admin')
        ? Response::allow()
        : Response::deny('You do not have permission to create Task Templates.');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update_template(User $user): Response
    {
        return $user->hasRole('admin')
        ? Response::allow()
        : Response::deny('You do not have permission to update Task Templates.');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete_template(User $user, TaskTemplate $taskTemplate): Response
    {
        return $user->hasRole('admin')
        ? Response::allow()
        : Response::deny('You do not have permission to delete Task Templates.');
    }
}
