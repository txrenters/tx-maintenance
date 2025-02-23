<?php

namespace App\Policies;

use App\Models\TwilioPhoneNumber;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TwilioPhoneNumberPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function view_twilio_number(User $user): Response
    {
        return $user->hasRole('admin')
            ? Response::allow()
            : Response::deny('You do not have permission to view Twilio Phone Numbers.');
    }
}
