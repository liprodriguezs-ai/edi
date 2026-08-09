<?php

namespace App\Http\Requests;

use App\Models\NotificationPreference;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the self-service update of the authenticated user's notification
 * preferences (§17.3).
 *
 * `channels` is an optional map of category => { push:bool, email:bool, sms:bool }.
 * Only the recognized channel keys are allowed inside each category map.
 */
class NotificationPreferenceUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'channels' => ['nullable', 'array'],
            'channels.*' => ['array'],
            'channels.*.*' => ['boolean'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i,H:i:s'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i,H:i:s', 'required_with:quiet_hours_start'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $channels = $this->input('channels');
            if (! is_array($channels)) {
                return;
            }

            foreach ($channels as $category => $map) {
                if (! is_array($map)) {
                    continue;
                }
                foreach (array_keys($map) as $channelKey) {
                    if (! in_array($channelKey, NotificationPreference::CHANNELS, true)) {
                        $validator->errors()->add(
                            "channels.{$category}.{$channelKey}",
                            'Canal de notificación no soportado: '.$channelKey,
                        );
                    }
                }
            }
        });
    }

    public function authorize(): bool
    {
        return true;
    }
}
