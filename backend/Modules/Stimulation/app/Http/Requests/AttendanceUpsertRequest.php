<?php

namespace Modules\Stimulation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use QCH\Core\Support\TenantTime;

class AttendanceUpsertRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Group-dependent checks (active group, active membership, session_date
     * >= group.start_date) live in StimulationAttendanceService (design §5) —
     * this Request only validates payload SHAPE and per-entry FK existence.
     */
    public function rules(): array
    {
        return [
            'session_date' => ['required', 'date', 'before_or_equal:'.TenantTime::today()],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.child_id' => [
                'required',
                'uuid',
                Rule::exists('child', 'id')->where('tenant_id', tenant('id')),
            ],
            'entries.*.attended' => ['sometimes', 'boolean'],
            'entries.*.homework_done' => ['sometimes', 'boolean'],
            'entries.*.attendance_payment_status_id' => [
                'nullable',
                'uuid',
                Rule::exists('attendance_payment_status', 'id'),
            ],
        ];
    }

    /**
     * REQ-SA-002 — entries must carry distinct child_ids.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $entries = $this->input('entries', []);
            if (! is_array($entries)) {
                return;
            }

            $childIds = array_filter(array_map(
                fn ($entry) => is_array($entry) ? ($entry['child_id'] ?? null) : null,
                $entries
            ));

            if (count($childIds) !== count(array_unique($childIds))) {
                $validator->errors()->add('entries', 'No puedes repetir el mismo niño en la lista de asistencia.');
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
