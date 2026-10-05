<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ChatbotWebhookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $secret = (string) config('services.chatbot.webhook_secret');
        $timestamp = (string) $this->header('X-Hub-Timestamp', '');

        return config('services.chatbot.enabled') && $secret !== ''
            && ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= 300
            && hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$this->getContent(), $secret), (string) $this->header('X-Hub-Signature'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'event' => ['required', 'string'],
            'occurred_at' => ['required', 'date'],
            'data' => ['required', 'array'],
            'data.thread' => ['required', 'array'],
            'data.thread.id' => ['required'],
            'data.thread.work_order_id' => ['nullable', 'string'],
            'data.thread.work_order_party' => ['nullable', 'string'],
            'data.thread.phone' => ['nullable', 'string'],
            'data.message' => ['sometimes', 'array'],
        ];
    }
}
