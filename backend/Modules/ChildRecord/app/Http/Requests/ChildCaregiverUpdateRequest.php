<?php

namespace Modules\ChildRecord\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChildCaregiverUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * child_id/caregiver_id are immutable after create (relink by deleting and
     * re-creating the link) — only the kinship label and the primary flag are
     * editable. Primary-flag transitions are handled by ChildCaregiverService.
     */
    public function rules(): array
    {
        return [
            'caregiver_relationship_id' => [
                'sometimes',
                'uuid',
                Rule::exists('caregiver_relationship', 'id')->where('tenant_id', tenant('id')),
            ],
            'is_primary' => ['sometimes', 'boolean'],
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
