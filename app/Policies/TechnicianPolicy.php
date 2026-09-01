<?php

namespace App\Policies;

use App\Models\Technician;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Admin + WOC: coordinators own the roster day-to-day, the same gate as
 * the other settings pages they use.
 */
class TechnicianPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->staff($user, 'view technicians');
    }

    public function create(User $user): Response
    {
        return $this->staff($user, 'add technicians');
    }

    public function update(User $user, Technician $technician): Response
    {
        return $this->staff($user, 'edit technicians');
    }

    public function delete(User $user, Technician $technician): Response
    {
        return $this->staff($user, 'delete technicians');
    }

    private function staff(User $user, string $action): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny("You do not have permission to {$action}.");
    }
}
