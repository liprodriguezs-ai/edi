<?php

namespace Modules\Complaint\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ComplaintAssignRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'assigned_user_id' => ['required', 'uuid', Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id'))],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
