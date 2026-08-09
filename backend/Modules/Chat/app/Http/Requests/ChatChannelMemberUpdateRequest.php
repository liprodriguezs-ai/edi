<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChatChannelMemberUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'notif_pref' => ['sometimes', 'string', Rule::in(['all', 'mentions', 'none'])],
            'last_read_at' => ['sometimes', 'nullable', 'date'],
            // Membership identity is immutable — re-create instead of re-pointing.
            'chat_channel_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
