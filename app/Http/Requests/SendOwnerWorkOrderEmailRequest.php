<?php

namespace App\Http\Requests;

use App\Models\Owner;
use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SendOwnerWorkOrderEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        $owner = $this->route('owner');

        return Gate::allows('update_owner', $owner instanceof Owner ? $owner : Owner::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $owner = $this->route('owner');
        $workOrder = $this->route('workOrder');

        return [
            'owner_id' => [
                Rule::requiredIf($workOrder instanceof WorkOrder),
                'integer',
                Rule::exists('work_order_owners', 'owner_id')
                    ->where('work_order_id', $workOrder instanceof WorkOrder ? $workOrder->id : 0),
            ],
            'work_order_id' => [
                'nullable',
                'integer',
                Rule::exists('work_order_owners', 'work_order_id')
                    ->where('owner_id', $owner instanceof Owner ? $owner->id : 0),
            ],
            'to' => ['required', 'email:rfc', 'max:255'],
            'from_email' => [
                'required',
                'email:rfc',
                'max:255',
            ],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }
}
