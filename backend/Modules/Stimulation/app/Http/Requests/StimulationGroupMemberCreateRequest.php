<?php

namespace Modules\Stimulation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Stimulation\Models\StimulationGroup;

class StimulationGroupMemberCreateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'stimulation_group_id' => [
                'required',
                'uuid',
                Rule::exists('stimulation_group', 'id')->where('tenant_id', tenant('id')),
            ],
            'child_id' => [
                'required',
                'uuid',
                Rule::exists('child', 'id')->where('tenant_id', tenant('id')),
                Rule::unique('stimulation_group_member', 'child_id')
                    ->where('tenant_id', tenant('id'))
                    ->where('stimulation_group_id', $this->input('stimulation_group_id'))
                    ->whereNull('deleted_at'),
            ],
            'default_attendance_payment_status_id' => [
                'required',
                'uuid',
                Rule::exists('attendance_payment_status', 'id'),
            ],
            'enrolled_at' => ['nullable', 'date'],
        ];
    }

    /**
     * REQ-SG-002 — enrollment into an inactive group fails with 422 (D11).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $groupId = $this->input('stimulation_group_id');
            if (! $groupId) {
                return;
            }

            $group = StimulationGroup::where('tenant_id', tenant('id'))->find($groupId);
            if ($group && ! $group->is_active) {
                $validator->errors()->add('stimulation_group_id', 'El grupo está inactivo y no acepta nuevas inscripciones.');
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
