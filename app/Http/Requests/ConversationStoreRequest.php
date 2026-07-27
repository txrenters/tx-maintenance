<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConversationStoreRequest extends FormRequest
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
            'text' => 'nullable|string|max:1600',
            'work_order_id' => 'required|integer|exists:work_orders,id',
            'sender_phone_number' => 'nullable|string',
            'receiver_phone_number' => 'nullable|string',
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'conversation_type' => 'required|string|max:50',
            'images' => 'nullable|array|max:10',
            // Images, videos, and PDFs are all accepted. Recipients receive the
            // media as a link in the SMS body (not a true MMS attachment), so video
            // and documents are safe to allow; the size cap only bounds
            // upload/storage. 50MB per file.
            'images.*' => 'file|mimes:jpeg,png,jpg,gif,svg,webp,mp4,mov,m4v,3gp,3gpp,webm,pdf|max:51200',
        ];
    }
}
