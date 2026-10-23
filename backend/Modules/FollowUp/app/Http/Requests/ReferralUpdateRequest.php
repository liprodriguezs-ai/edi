<?php

namespace Modules\FollowUp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * follow_up_id and referral_specialty_id are immutable after create; update
 * touches only the outcome fields.
 */
class ReferralUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'referred_at' => ['sometimes', 'date'],
            'institution' => ['sometimes', 'nullable', 'string', 'max:255'],
            'outcome_notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
