<?php

namespace App\Http\Requests;

use App\Models\Tenants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SendTenantJobberEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update_tenant', Tenants::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->route('tenant');

        return [
            'jobber_job_id' => [
                'nullable',
                'integer',
                Rule::exists('jobber_job_tenant', 'jobber_job_id')
                    ->where('tenant_id', $tenant instanceof Tenants ? $tenant->id : 0),
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
