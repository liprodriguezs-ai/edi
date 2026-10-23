<?php

namespace Modules\FollowUp\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * REQ-FUP-006 -- manual create. child_id is NEVER accepted from the client:
 * the controller derives it from the screening (same pattern as
 * ConfirmatoryTestController::performStore). Eligibility (completed
 * screening) is enforced by the controller, not here.
 */
class FollowUpCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'screening_id' => [
                'required',
                'uuid',
                Rule::exists('screening', 'id')->where('tenant_id', tenant('id')),
            ],
            'follow_up_type_id' => [
                'required',
                'uuid',
                Rule::exists('follow_up_type', 'id'),
            ],
            'due_date' => ['required', 'date'],
            'referral_specialty_id' => [
                'nullable',
                'uuid',
                Rule::exists('referral_specialty', 'id')->where('tenant_id', tenant('id')),
            ],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
