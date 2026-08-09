<?php

namespace Modules\Housing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))],
            // whereNull('deleted_at') so a soft-deleted vehicle's plate is NOT treated as
            // taken — matches the live-only partial unique index (vehicle_plate_unique).
            'plate' => ['required', 'string', 'max:20', Rule::unique('vehicle', 'plate')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:50'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
