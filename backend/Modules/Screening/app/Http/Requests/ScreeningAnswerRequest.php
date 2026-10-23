<?php

namespace Modules\Screening\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * REQ-SCR-003/004 — whitelists ONLY answers[]{screening_item_id,
 * screening_item_option_id} and risk_factor_answers[]{risk_factor_id,
 * is_present}. No color, no result field can ever enter through here.
 */
class ScreeningAnswerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'answers' => ['sometimes', 'array'],
            'answers.*.screening_item_id' => ['required', 'uuid'],
            'answers.*.screening_item_option_id' => ['required', 'uuid'],
            'risk_factor_answers' => ['sometimes', 'array'],
            'risk_factor_answers.*.risk_factor_id' => ['required', 'uuid'],
            'risk_factor_answers.*.is_present' => ['required', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
