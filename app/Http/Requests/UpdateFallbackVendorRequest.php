<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFallbackVendorRequest extends FormRequest
{
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
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'priority' => ['required', 'integer', 'min:1', 'max:255'],
            'is_active' => ['boolean'],
            'contacts' => ['required', 'array', 'min:1'],
            'contacts.*.name' => ['nullable', 'string', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:50'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'issue_types' => ['required', 'array', 'min:1'],
            'issue_types.*' => ['required', 'string', 'max:100'],
            'keywords' => ['required', 'array', 'min:1'],
            'keywords.*' => ['required', 'string', 'max:100'],
        ];
    }
}
