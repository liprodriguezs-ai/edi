<?php

namespace Modules\Stimulation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use QCH\Core\Support\TenantTime;

class StimulationSessionUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'child_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('child', 'id')->where('tenant_id', tenant('id')),
            ],
            'stimulation_group_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('stimulation_group', 'id')->where('tenant_id', tenant('id')),
            ],
            'visit_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('visit', 'id')
                    ->where('tenant_id', tenant('id'))
                    ->where('child_id', $this->input('child_id')),
            ],
            'session_date' => ['sometimes', 'date', 'before_or_equal:'.TenantTime::today()],
            'practitioner_id' => [
                'sometimes',
                'uuid',
                Rule::exists('practitioner_profile', 'id')->where('tenant_id', tenant('id')),
            ],
            'integration_dynamic_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('stimulation_dynamic', 'id')->where('tenant_id', tenant('id')),
            ],
            'integration_material' => ['sometimes', 'nullable', 'string', 'max:255'],
            'integration_song' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preparation_song' => ['sometimes', 'nullable', 'string', 'max:255'],
            'core_stimulation_area_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('stimulation_area', 'id')],
            'core_dynamic_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('stimulation_dynamic', 'id')->where('tenant_id', tenant('id')),
            ],
            'core_activity' => ['sometimes', 'nullable', 'string'],
            'core_material' => ['sometimes', 'nullable', 'string', 'max:255'],
            'core_song' => ['sometimes', 'nullable', 'string', 'max:255'],
            'relaxation_notes' => ['sometimes', 'nullable', 'string'],
            'observations' => ['sometimes', 'nullable', 'string'],

            'movements' => ['sometimes', 'array'],
            'movements.*.body_zone_id' => [
                'required',
                'uuid',
                Rule::exists('body_zone', 'id')->where('tenant_id', tenant('id')),
            ],
            'movements.*.massage_movement_id' => [
                'required',
                'uuid',
                Rule::exists('massage_movement', 'id')->where('tenant_id', tenant('id')),
            ],

            'relaxation_zones' => ['sometimes', 'array'],
            'relaxation_zones.*.body_zone_id' => [
                'required',
                'uuid',
                Rule::exists('body_zone', 'id')->where('tenant_id', tenant('id')),
            ],
        ];
    }

    /**
     * REQ-SS-002 (subject XOR — only enforced when the payload touches a
     * subject field) and REQ-SS-003 (in-payload duplicate pairs).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('child_id') || $this->has('stimulation_group_id')) {
                $hasChild = filled($this->input('child_id'));
                $hasGroup = filled($this->input('stimulation_group_id'));

                if ($hasChild === $hasGroup) {
                    $validator->errors()->add('child_id', 'Debes indicar exactamente un sujeto: niño o grupo.');
                }
            }

            $this->rejectDuplicatePairs($validator, 'movements', ['body_zone_id', 'massage_movement_id']);
            $this->rejectDuplicatePairs($validator, 'relaxation_zones', ['body_zone_id']);
        });
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function rejectDuplicatePairs(Validator $validator, string $field, array $keys): void
    {
        $entries = $this->input($field, []);
        if (! is_array($entries)) {
            return;
        }

        $seen = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $signature = implode('|', array_map(fn (string $key) => (string) ($entry[$key] ?? ''), $keys));
            if (isset($seen[$signature])) {
                $validator->errors()->add($field, 'No puedes repetir la misma combinación en '.$field.'.');

                return;
            }
            $seen[$signature] = true;
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
