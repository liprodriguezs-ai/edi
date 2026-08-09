<?php

namespace Modules\ChildRecord\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'visit_date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
