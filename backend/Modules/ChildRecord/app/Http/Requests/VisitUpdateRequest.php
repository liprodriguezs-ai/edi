<?php

namespace Modules\ChildRecord\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VisitUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * receipt_number is deliberately absent (immutable, REQ-CH-008); child_id
     * is immutable after create (a visit belongs to the child it was created for).
     */
    public function rules(): array
    {
        return [
            'health_center_id' => [
                'sometimes',
                'uuid',
                Rule::exists('health_center', 'id')->where('tenant_id', tenant('id')),
            ],
            'practitioner_id' => [
                'sometimes',
                'uuid',
                Rule::exists('practitioner_profile', 'id')->where('tenant_id', tenant('id')),
            ],
            'physical_receipt_number' => ['nullable', 'string', 'max:255'],
            'visit_date' => ['sometimes', 'date', 'before_or_equal:today'],
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
