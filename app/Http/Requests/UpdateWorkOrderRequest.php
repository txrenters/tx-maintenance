<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'work_order_no' => 'required',
            'category' => 'nullable|string',
            'zone' => 'nullable|string',
            'management_plan' => 'nullable|string',
            'closing_comments' => 'nullable|string',
            'latest_update_comments' => 'nullable|string',
            'additional_work_needed_reschedule' => 'nullable|string',
            'description' => 'nullable|string',
        ];
    }
}
