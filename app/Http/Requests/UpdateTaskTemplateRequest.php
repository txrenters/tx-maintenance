<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskTemplateRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string',
            'description' => 'nullable|string',
            'current_service_status_id' => 'required',
            'is_current_service_status_emergency' => 'required|string|in:Emergency,Non-emergency',
            'next_service_status_id' => 'required',
            'is_next_service_status_emergency' => 'required|string|in:Emergency,Non-emergency',
            'tasks' => 'required|array',
            'tasks.*.name' => 'required|string',
            'tasks.*.is_option' => 'required|string|in:Yes,No',
            'tasks.*.is_mandatory' => 'required|string|in:Yes,No',
            'tasks.*.task_for' => 'required|string',
            'tasks.*.assigned_user_id' => 'nullable|integer|exists:users,id',
            'tasks.*.due_date' => 'required|string',
            'tasks.*.task_service_status_id' => 'required_if:tasks.*.is_option,No',
            'tasks.*.is_task_service_status_emergency' => 'required_if:tasks.*.is_option,No',
            'tasks.*.is_task_service_status_emergency' => 'required_if:tasks.*.is_option,No',

            'tasks.*.task_details' => 'required_if:tasks.*.is_option,Yes|array|min:2', // Must have at least 2 details
            'tasks.*.task_details.*.task_for' => 'required_if:tasks.*.is_option,Yes',
            'tasks.*.task_details.*.task_service_status_id' => 'required_if:tasks.*.is_option,Yes',
            'tasks.*.task_details.*.is_task_service_status_emergency' => 'required_if:tasks.*.is_option,Yes',
        ];
    }

    public function messages()
    {
        return [
            'tasks.*.name.required' => 'The task name field is required.',
            'tasks.*.is_option.required' => 'The task option field is required.',
            'tasks.*.is_mandatory.required' => 'The task mandatory field is required.',
            'tasks.*.due_date.required' => 'The task due date field is required.',
            'tasks.*.task_for.required' => 'The task for field is required.',
            'tasks.*.task_service_status_id.required_if' => 'The task service status field is required if option is No.',
            'tasks.*.is_task_service_status_emergency.required_if' => 'The task service status emergency field is required if option is No.',

            'tasks.*.task_details.required_if' => 'The task details field is required if option is Yes.', // Must have at least 2 details
            'tasks.*.task_details.*.task_for.required_if' => 'The task details for field is required if option is Yes',
            'tasks.*.task_details.*.task_service_status_id.required_if' => 'The task details service status field is required if option is Yes',
            'tasks.*.task_details.*.is_task_service_status_emergency.required_if' => 'The task details emergency field is required if option is Yes',
        ];
    }
}
