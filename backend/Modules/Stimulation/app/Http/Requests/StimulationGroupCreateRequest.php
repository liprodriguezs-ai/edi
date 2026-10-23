<?php

namespace Modules\Stimulation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StimulationGroupCreateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stimulation_group', 'name')
                    ->where('tenant_id', tenant('id'))
                    ->whereNull('deleted_at'),
            ],
            'stimulation_group_type_id' => [
                'required',
                'uuid',
                Rule::exists('stimulation_group_type', 'id')->where('tenant_id', tenant('id')),
            ],
            'health_center_id' => [
                'required',
                'uuid',
                Rule::exists('health_center', 'id')->where('tenant_id', tenant('id')),
            ],
            'promoter_user_id' => [
                'required',
                'uuid',
                Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id')),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * REQ-SG-001 — end_date must be >= start_date when both are present.
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
