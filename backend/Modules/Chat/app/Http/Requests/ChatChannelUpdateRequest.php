<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatChannel;

class ChatChannelUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $channelId = $this->route('chat_channel') ?? $this->route('id');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('chat_channel', 'name')->where('tenant_id', tenant('id'))->ignore($channelId)],
            'chat_channel_type_id' => ['sometimes', 'uuid', Rule::exists('chat_channel_type', 'id')],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'target_type' => ['sometimes', 'nullable', 'string', Rule::in(ChatChannel::ADMIN_TARGET_TYPES)],
            'target_selector' => ['sometimes', 'nullable', 'array'],
            // WRITE target: who may post (same vocabulary as the read target).
            'write_target_type' => ['sometimes', 'nullable', 'string', Rule::in(ChatChannel::ADMIN_TARGET_TYPES)],
            'write_target_selector' => ['sometimes', 'nullable', 'array'],
            'is_moderated' => ['sometimes', 'boolean'],
            'is_archived' => ['sometimes', 'boolean'],
            'notify_on_post' => ['sometimes', 'boolean'],
            // System flag is immutable from the API.
            'is_system' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Closed set (same vocabulary as charges) — an unknown scope must 422,
            // never silently fall back to "todo el condominio".
            'target_type.in' => 'El alcance del canal no es válido.',
            'write_target_type.in' => 'El alcance de escritura del canal no es válido.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
