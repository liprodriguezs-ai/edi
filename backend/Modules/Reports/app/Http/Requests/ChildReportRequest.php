<?php

namespace Modules\Reports\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string filters shared by the Concentrado EDI and the Reporte
 * general (listing and export). Health centers must belong to the current
 * tenant; the date range applies to the child's last counted Prueba EDI.
 */
class ChildReportRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'health_center_ids' => ['nullable', 'array'],
            'health_center_ids.*' => [
                'uuid',
                Rule::exists('health_center', 'id')->where('tenant_id', tenant('id')),
            ],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array{health_center_ids: string[], from: ?string, to: ?string, search: ?string}
     */
    public function filters(): array
    {
        return [
            'health_center_ids' => $this->validated('health_center_ids') ?? [],
            'from' => $this->validated('from'),
            'to' => $this->validated('to'),
            'search' => $this->validated('search'),
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
