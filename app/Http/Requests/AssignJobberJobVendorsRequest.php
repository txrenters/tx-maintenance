<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignJobberJobVendorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasAnyRole(['admin', 'woc']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vendor_ids' => ['present', 'array'],
            'vendor_ids.*' => ['integer', 'exists:vendors,id'],
        ];
    }
}
