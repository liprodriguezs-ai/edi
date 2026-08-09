<?php

namespace Modules\Complaint\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ComplaintUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:150'],
            'complaint_category_id' => ['sometimes', 'uuid', Rule::exists('complaint_category', 'id')->where('tenant_id', tenant('id'))],
            'complaint_priority_id' => ['sometimes', 'uuid', Rule::exists('complaint_priority', 'id')->where('tenant_id', tenant('id'))],
            'against_unit_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))],
            'against_description' => ['sometimes', 'string', 'max:2000'],
            // NOTE (review W2): evidence is intentionally NOT validated here — update()
            // has no performUpdate hook to attach it (unlike register()), so a validated
            // file would be silently discarded. Post-creation evidence arrives via
            // message attachments (complaint/{id}/message), not this endpoint.
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
