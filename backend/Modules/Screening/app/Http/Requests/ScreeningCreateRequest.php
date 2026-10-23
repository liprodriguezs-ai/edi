<?php

namespace Modules\Screening\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Instrument\Models\InstrumentVersion;

/**
 * REQ-SCR-002/003/008 — whitelists ONLY visit_id + somatometry + notes. The
 * server resolves everything else (child, version, ages, groups, status).
 * Somatometry ranges mirror design D12.
 *
 * `age_group_id` is the one exception ("Prueba EDI a elección", S2): the
 * evaluator may pick the group to apply. It must be an OFFICIAL group of the
 * version the draft will be frozen to (the default published one) --
 * alternates are reached only through their official group.
 */
class ScreeningCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'visit_id' => [
                'required',
                'uuid',
                Rule::exists('visit', 'id')->where('tenant_id', tenant('id')),
            ],
            'weight_grams' => ['nullable', 'integer', 'between:1500,40000'],
            'height_mm' => ['nullable', 'integer', 'between:350,1400'],
            'head_circumference_mm' => ['nullable', 'integer', 'between:250,700'],
            'notes' => ['nullable', 'string'],
            'age_group_id' => [
                'nullable',
                'uuid',
                // Closure form: Rule::exists()->where('is_alternate', false) stringifies
                // the boolean to '' and Postgres rejects it.
                Rule::exists('age_group', 'id')->where(fn ($query) => $query
                    ->where('instrument_version_id', $this->defaultVersionId())
                    ->where('is_alternate', false)
                    ->whereNull('deleted_at')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'weight_grams.between' => 'El peso está fuera del rango plausible.',
            'height_mm.between' => 'La talla está fuera del rango plausible.',
            'head_circumference_mm.between' => 'El perímetro cefálico está fuera del rango plausible.',
            'age_group_id.exists' => 'El grupo elegido no es un grupo oficial de la versión vigente de la Prueba EDI.',
            'age_group_id.uuid' => 'El grupo elegido no es válido.',
        ];
    }

    /**
     * Same version resolution as ScreeningApplicationService::createDraft for
     * the regular flow (the default published version).
     */
    private function defaultVersionId(): string
    {
        return (string) InstrumentVersion::query()
            ->where('is_default', true)
            ->whereNotNull('published_at')
            ->value('id');
    }

    public function authorize(): bool
    {
        return true;
    }
}
