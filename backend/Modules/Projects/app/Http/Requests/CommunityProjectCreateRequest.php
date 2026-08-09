<?php

namespace Modules\Projects\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Collections\Services\TargetResolver;
use Modules\Projects\Models\CommunityProjectStatus;
use Orion\Http\Requests\Request;

class CommunityProjectCreateRequest extends Request
{
    /**
     * §11.4 form fields. Amounts are integer minor units, capped by the global
     * §18.7.4 rule (< $10,000,000 MXN). The status is NOT a free field: a project is
     * always born as DRAFT; activation/execution/cancellation go through the custom
     * endpoints (FSM, finding projects-10).
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'description_html' => ['nullable', 'string', 'max:20000'],
            'community_project_category_id' => ['required', 'uuid', 'exists:community_project_category,id'],
            'participation_type_id' => ['required', 'uuid', 'exists:participation_type,id'],
            'amount_mode_id' => ['required', 'uuid', 'exists:amount_mode,id'],
            'fixed_amount' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'min_amount' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'ideal_amount' => ['nullable', 'integer', 'min:0', 'max:999999999', $this->coherentWith('min_amount', 'El monto ideal debe ser mayor o igual al mínimo.')],
            'max_amount' => ['nullable', 'integer', 'min:0', 'max:999999999', $this->coherentWith('ideal_amount', 'El monto máximo debe ser mayor o igual al ideal.')],
            'allow_decline' => ['nullable', 'boolean'],
            'target_type' => ['required', 'string', Rule::in(TargetResolver::TARGET_TYPES)],
            'target_selector' => ['nullable', 'array'],
            'goal_amount' => ['nullable', 'integer', 'min:0', 'max:999999999'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', $this->dateNotBefore('start_date', 'La fecha límite no puede ser anterior a la fecha de inicio.')],
            'responsible_user_id' => ['nullable', 'uuid', 'exists:user,id'],
            'visible_to_residents' => ['nullable', 'boolean'],
            'payments_visible_to_residents' => ['nullable', 'boolean'],
            'allow_comments' => ['nullable', 'boolean'],
            'community_project_status_id' => ['nullable', 'uuid', Rule::in([CommunityProjectStatus::DRAFT])],
        ];
    }

    /**
     * Closure rule: the validated amount must be >= the other amount when both are
     * present. Implemented as a closure (not gte:) so an absent counterpart never
     * fails the comparison.
     */
    protected function coherentWith(string $otherField, string $message): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($otherField, $message): void {
            $other = $this->input($otherField);

            if ($other !== null && $other !== '' && is_numeric($value) && (int) $value < (int) $other) {
                $fail($message);
            }
        };
    }

    /**
     * Closure rule: date must not be before the other date when both are present.
     */
    protected function dateNotBefore(string $otherField, string $message): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($otherField, $message): void {
            $other = $this->input($otherField);

            if ($other && $value && strtotime((string) $value) < strtotime((string) $other)) {
                $fail($message);
            }
        };
    }
}
