<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DownloadConversationPdfRequest extends FormRequest
{
    /**
     * Staff only: the PDF is a record coordinators hand on, not a party view.
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
            'title' => ['required', 'string', 'max:100'],
            'our_number' => ['nullable', 'string', 'max:30'],
            'message_ids' => ['present', 'array', 'max:5000'],
            'message_ids.*' => ['integer'],
            'participants' => ['nullable', 'array', 'max:50'],
            'participants.*.phone' => ['nullable', 'string', 'max:30'],
            'participants.*.name' => ['nullable', 'string', 'max:150'],
            'participants.*.role' => ['nullable', 'string', 'max:50'],
        ];
    }
}
