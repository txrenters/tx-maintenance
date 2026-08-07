<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable'],
            'company' => ['nullable'],
            'address' => ['nullable'],
            'website' => ['nullable'],
            'photo' => ['nullable', 'mimes:jpg,jpeg,png', 'max:1024'],
        ])->validateWithBag('updateProfileInformation');

        if (isset($input['photo'])) {
            $user->updateProfilePhoto($input['photo']);
        }

        if ($input['email'] !== $user->email &&
            $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill($this->attributesFrom($user, $input))->save();
        }
    }

    /**
     * The optional fields are validated "nullable", so a form that omits one
     * is valid — reading them unconditionally 500'd the profile update.
     *
     * An absent key keeps what the user already had rather than nulling it, so
     * a partial submission cannot silently wipe a phone number. Clearing a
     * field still works: the form posts it as an empty string.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function attributesFrom(User $user, array $input): array
    {
        return [
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'] ?? $user->phone,
            'company' => $input['company'] ?? $user->company,
            'address' => $input['address'] ?? $user->address,
            'website' => $input['website'] ?? $user->website,
        ];
    }

    /**
     * Update the given verified user's profile information.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        $user->forceFill($this->attributesFrom($user, $input) + [
            'email_verified_at' => null,
        ])->save();

        $user->sendEmailVerificationNotification();
    }
}
