<?php

namespace Modules\FollowUp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-FUP-006 -- update allows ONLY due_date, notes, referral_specialty_id
 * while open. The terminal freeze (409 FOLLOW_UP_FROZEN) is enforced by
 * FollowUpController::performUpdate, not here.
 */
class FollowUpUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'due_date' => ['sometimes', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'referral_specialty_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('referral_specialty', 'id')->where('tenant_id', tenant('id')),
            ],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
