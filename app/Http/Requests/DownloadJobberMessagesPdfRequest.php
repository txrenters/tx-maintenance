<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DownloadJobberMessagesPdfRequest extends FormRequest
{
    /**
     * Staff only, matching the work order conversation PDF.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasAnyRole(['admin', 'woc', 'accounting']);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'our_number' => ['nullable', 'string', 'max:30'],
        ];
    }
}
