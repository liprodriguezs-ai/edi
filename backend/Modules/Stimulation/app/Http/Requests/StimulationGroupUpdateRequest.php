<?php

namespace Modules\Stimulation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StimulationGroupUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('stimulation_group', 'name')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at')
                    ->ignore($this->route('stimulation_group')),
            ],
            'stimulation_group_type_id' => [
                'sometimes',
                'uuid',
                Rule::exists('stimulation_group_type', 'id')->where('tenant_id', tenant('id')),
            ],
            'health_center_id' => [
                'sometimes',
                'uuid',
                Rule::exists('health_center', 'id')->where('tenant_id', tenant('id')),
            ],
            'promoter_user_id' => [
                'sometimes',
                'uuid',
                Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id')),
            ],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * REQ-SG-001 — end_date must be >= start_date, checked only when BOTH are
     * present in the SAME payload (a partial update touching only one of the
     * two never trips a false comparison — same pattern as
     * BillingPackageCreateRequest).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = $this->input('start_date');
            $end = $this->input('end_date');

            if ($start && $end && strtotime((string) $end) < strtotime((string) $start)) {
                $validator->errors()->add('end_date', 'La fecha de fin debe ser igual o posterior a la fecha de inicio.');
            }
        });
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
