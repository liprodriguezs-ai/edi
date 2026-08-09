<?php

namespace Modules\Tracking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Tracking\DTO\TrackingEvent;

/**
 * Validates POST /api/track. Whitelists exactly the wire contract shared with
 * the landing and the React app — no free-form fields reach the channels.
 * `authorize()` always true: this is a public, unauthenticated, throttled
 * endpoint (no permission model applies).
 */
class TrackEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * NOTE: Laravel's `excludeUnvalidatedArrayKeys` behavior means any
     * `params.*`/`user_data.*` sub-key WITHOUT an explicit rule below is
     * SILENTLY dropped from `validated()` — it never reaches the DTO, no error
     * is raised. Adding a new field to the wire contract requires adding its
     * rule HERE too, or it will vanish without a trace.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'event' => ['required', 'string', 'in:'.implode(',', TrackingEvent::CANONICAL_EVENTS)],
            'event_id' => ['required', 'uuid'],
            'event_time' => ['nullable', 'integer', 'min:0'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            // This public endpoint is BROWSER-only, so "website" is the only valid
            // action_source here. "system_generated" is reserved for the server-side
            // hooks (StripeWebhookListener, AuthController::register), which build
            // the TrackingEvent DTO directly and never go through this request.
            'action_source' => ['nullable', 'string', 'in:website'],

            'params' => ['nullable', 'array'],
            'params.value' => ['nullable', 'numeric'],
            'params.currency' => ['nullable', 'string', 'size:3'],
            'params.content_ids' => ['nullable', 'array'],
            'params.content_name' => ['nullable', 'string', 'max:255'],
            'params.transaction_id' => ['nullable', 'string', 'max:255'],

            'user_data' => ['nullable', 'array'],
            'user_data.email' => ['nullable', 'string', 'max:255'],
            'user_data.phone' => ['nullable', 'string', 'max:32'],
            'user_data.external_id' => ['nullable', 'string', 'max:255'],
            'user_data.fbp' => ['nullable', 'string', 'max:255'],
            'user_data.fbc' => ['nullable', 'string', 'max:255'],
            'user_data.gclid' => ['nullable', 'string', 'max:255'],
        ];
    }
}
