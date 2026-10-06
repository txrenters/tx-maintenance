<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The "Create Work Order" form on the Crystal Creek Air board: a customer who
 * is not a Texas Renters property, typed in by the office from the call.
 */
class CrystalCreekWorkOrderRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:190'],
            // One way to reach the customer is enough, but there must be one.
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:190', 'required_without:phone'],
            'street' => ['required', 'string', 'max:190'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'size:2'],
            'postal_code' => ['required', 'string', 'regex:/^\d{5}(-\d{4})?$/'],
            'category' => ['required', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required_without' => 'Enter a phone number or an email address.',
            'email.required_without' => 'Enter a phone number or an email address.',
            'state.size' => 'Use the two-letter state code, for example TX.',
            'postal_code.regex' => 'Enter a 5-digit ZIP code.',
        ];
    }
}
