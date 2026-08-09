<?php

namespace Modules\Collections\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Collections\Services\TargetResolver;

/**
 * Charge batch update (spec §8.4): editable metadata only. The lifecycle
 * (draft → active → cancelled) and progress are service-owned — CANCELLED is
 * only reachable through charge-batch/{id}/cancel, which also cancels the
 * generated charges (finding collections-13). The source policy is immutable.
 */
class ChargeBatchUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'year' => ['sometimes', 'required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'target_type' => ['sometimes', 'required', 'string', Rule::in(TargetResolver::TARGET_TYPES)],
            'target_selector' => ['sometimes', 'nullable', 'array'],
            'issued_at' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'required', 'date'],

            // Immutable / service-owned columns.
            'charge_policy_id' => ['prohibited'],
            'charge_batch_status_id' => ['prohibited'],
            'progress_pct' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_type.in' => 'El alcance del cobro no es válido.',
            'charge_batch_status_id.prohibited' => 'El estado del cobro lo administra el sistema (usa activar/cancelar).',
            'charge_policy_id.prohibited' => 'La política de origen de un cobro no se puede cambiar.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
