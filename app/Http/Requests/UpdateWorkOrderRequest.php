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
            'cost_estimate' => 'nullable|numeric',
            'hour_estimate' => 'nullable|numeric',
            'zone' => 'nullable|string',
            'end_date' => 'nullable|date',
            'management_plan' => 'nullable|string',
            'closing_comments' => 'nullable|string',
            'is_emergency' => 'string',
            'additional_work_needed_reschedule' => 'nullable|string',
        ];
    }
}
