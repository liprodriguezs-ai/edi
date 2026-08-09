<?php

namespace Modules\Board\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BoardPeriodUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Raw PATCH/PUT may only touch descriptive fields. Lifecycle fields are
     * prohibited (§7.5.1): the status changes ONLY via POST /board_period/{id}/close
     * (which revokes member roles) or the start flow's auto-close, and dates are
     * fixed at start so the overlap/single-current invariants cannot be bypassed.
     */
    public function rules(): array
    {
        $periodId = $this->route('board_period');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('board_period', 'name')
                    ->where('tenant_id', tenant('id'))
                    ->ignore($periodId),
            ],
            'notes' => ['nullable', 'string'],
            'board_period_status_id' => ['prohibited'],
            'start_date' => ['prohibited'],
            'end_date' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'board_period_status_id.prohibited' => 'El estado del periodo solo cambia mediante el cierre de periodo.',
            'start_date.prohibited' => 'Las fechas del periodo no son editables después de iniciado.',
            'end_date.prohibited' => 'Las fechas del periodo no son editables después de iniciado.',
        ];
    }
}
