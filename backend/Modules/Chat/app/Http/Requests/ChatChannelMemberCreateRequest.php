<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChatChannelMemberCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'chat_channel_id' => ['required', 'uuid', Rule::exists('chat_channel', 'id')->where('tenant_id', tenant('id'))],
            // Central user table — membership in THIS tenant proven via user_tenant.
            'user_id' => ['required', 'uuid', Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id'))],
            'notif_pref' => ['sometimes', 'string', Rule::in(['all', 'mentions', 'none'])],
            'last_read_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
