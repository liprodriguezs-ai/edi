<?php

namespace Modules\ChildRecord\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ChildRecord\Models\Child;
use QCH\Core\Support\TenantTime;

class VisitCreateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * receipt_number is deliberately absent — it is always server-generated
     * (REQ-CH-008); a client-supplied value is silently ignored by VisitService.
     */
    public function rules(): array
    {
        return [
            'child_id' => [
                'required',
                'uuid',
                Rule::exists('child', 'id')->where('tenant_id', tenant('id')),
            ],
            'health_center_id' => [
                'required',
                'uuid',
                Rule::exists('health_center', 'id')->where('tenant_id', tenant('id')),
            ],
            'practitioner_id' => [
                'required',
                'uuid',
                Rule::exists('practitioner_profile', 'id')->where('tenant_id', tenant('id')),
            ],
            'physical_receipt_number' => ['nullable', 'string', 'max:255'],
            'visit_date' => ['required', 'date', 'before_or_equal:'.TenantTime::today()],
        ];
    }

    /**
     * A visit dated before the child was born is not merely odd data: the scoring
     * engine derives the age group from it, so it silently produces a screening
     * against the wrong item bank. `before_or_equal:today` alone never caught it
     * because the date is perfectly valid in isolation -- only the pair is wrong.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['child_id', 'visit_date'])) {
                return;
            }

            $birthDate = Child::query()
                ->whereKey($this->input('child_id'))
                ->value('birth_date');

            if ($birthDate && $this->date('visit_date')?->startOfDay()->lt($birthDate)) {
                $validator->errors()->add(
                    'visit_date',
                    'La fecha de la visita no puede ser anterior a la fecha de nacimiento.'
                );
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
