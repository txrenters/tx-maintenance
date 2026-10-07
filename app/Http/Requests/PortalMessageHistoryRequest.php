<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PortalMessageHistoryRequest extends FormRequest
{
    /**
     * EnsurePortalToken has already checked the caller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'contact' => ['required', 'string', 'regex:/^\d{1,20}$/'],
            'party' => ['required', 'in:tenant,owner'],
            // A PropertyWare work order id: everyone's texts on that work order's side.
            'work_order' => ['nullable', 'string', 'regex:/^\d{1,20}$/'],
        ];
    }
}
