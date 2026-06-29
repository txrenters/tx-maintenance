<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteAllClosedTasksRequest extends FormRequest
{
    /**
     * Only WOC/admin users may bulk-complete leftover tasks across every closed
     * work order — this is a wide, irreversible mutation.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->hasRole('admin') || $user->hasRole('woc'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
