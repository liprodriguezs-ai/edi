<?php

namespace Modules\Stimulation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StimulationGroupMemberUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * stimulation_group_id/child_id are immutable after enrollment (unenroll
     * and re-enroll to relink) — only the default payment status and the
     * enrollment date are editable.
     */
    public function rules(): array
    {
        return [
            'default_attendance_payment_status_id' => [
                'sometimes',
                'uuid',
                Rule::exists('attendance_payment_status', 'id'),
            ],
            'enrolled_at' => ['sometimes', 'date'],
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
