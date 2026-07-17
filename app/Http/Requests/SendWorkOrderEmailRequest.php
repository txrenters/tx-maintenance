<?php

namespace App\Http\Requests;

use App\Models\Vendor;
use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SendWorkOrderEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update_vendor', Vendor::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $workOrder = $this->route('workOrder');
        $vendor = $this->route('vendor');

        return [
            'vendor_id' => [
                'required',
                'integer',
                $workOrder instanceof WorkOrder
                    ? Rule::exists('work_order_vendors', 'vendor_id')->where('work_order_id', $workOrder->id)
                    : Rule::in([$vendor instanceof Vendor ? $vendor->id : 0]),
            ],
            'from_email' => ['required', 'email:rfc', 'max:255'],
            'to' => ['required', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }
}
