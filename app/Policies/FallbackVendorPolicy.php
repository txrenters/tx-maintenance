<?php

namespace App\Policies;

use App\Models\FallbackVendor;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FallbackVendorPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to view fallback vendors.');
    }

    public function create(User $user): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to create fallback vendors.');
    }

    public function update(User $user, FallbackVendor $fallbackVendor): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to update fallback vendors.');
    }

    public function delete(User $user, FallbackVendor $fallbackVendor): Response
    {
        return $user->hasRole('admin') || $user->hasRole('woc')
            ? Response::allow()
            : Response::deny('You do not have permission to delete fallback vendors.');
    }
}
