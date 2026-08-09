<?php

namespace Modules\Complaint\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ComplaintTransitionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // complaint_status is a CENTRAL catalog — no tenant scoping on the exists check.
            'complaint_status_id' => ['required', 'uuid', Rule::exists('complaint_status', 'id')],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
