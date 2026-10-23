<?php

namespace Modules\FollowUp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-FUP-007 -- type gate (specialist_referral only) and terminal freeze
 * are enforced by ReferralService, not here.
 */
class ReferralCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'follow_up_id' => [
                'required',
                'uuid',
                Rule::exists('follow_up', 'id')->where('tenant_id', tenant('id')),
            ],
            'referral_specialty_id' => [
                'required',
                'uuid',
                Rule::exists('referral_specialty', 'id')->where('tenant_id', tenant('id')),
            ],
            'referred_at' => ['required', 'date'],
            'institution' => ['nullable', 'string', 'max:255'],
            'outcome_notes' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
