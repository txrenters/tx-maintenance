<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        // Allow admins and WOC users to update tasks
        return $user && ($user->hasRole('admin') || $user->hasRole('woc'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'description' => 'required|string|max:1000',
            'due_date' => 'required|date',
            // Only users who can legitimately hold a task (staff/vendor roles) may be
            // assigned — prevents reassigning a task to an arbitrary user id (e.g. a tenant).
            'assigned_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->whereIn('id', User::role(['woc', 'admin', 'vendor'])->pluck('id'))
                ),
            ],
        ];
    }
}
