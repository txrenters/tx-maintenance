<?php

namespace App\Http\Requests\Desktop;

use App\Services\Desktop\DesktopTokenService;
use Illuminate\Foundation\Http\FormRequest;

class DesktopConnectRequest extends FormRequest
{
    /**
     * Only a setup code may complete the handshake; a device token that has
     * already been swapped carries "desktop:listen" and is rejected here.
     */
    public function authorize(): bool
    {
        return $this->user()?->tokenCan(DesktopTokenService::CONNECT_ABILITY) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'device_name' => ['required', 'string', 'max:100'],
            'client' => ['sometimes', 'nullable', 'string', 'max:100'],
            'client_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
