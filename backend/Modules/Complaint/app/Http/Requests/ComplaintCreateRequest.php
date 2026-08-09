<?php

namespace Modules\Complaint\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ComplaintCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            // complaint_category_id / complaint_priority_id are TENANT-scoped: the
            // exists check restricts to the current tenant's catalog rows.
            'complaint_category_id' => ['required', 'uuid', Rule::exists('complaint_category', 'id')->where('tenant_id', tenant('id'))],
            'complaint_priority_id' => ['required', 'uuid', Rule::exists('complaint_priority', 'id')->where('tenant_id', tenant('id'))],
            'against_unit_id' => ['nullable', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))],
            'against_description' => ['required', 'string', 'max:2000'],
            'evidence' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
