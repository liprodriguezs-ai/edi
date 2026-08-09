<?php

namespace Modules\Housing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VehicleUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $vehicleId = $this->route('vehicle') ?? $this->route('id');

        return [
            'unit_id' => ['sometimes', 'required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))],
            // whereNull('deleted_at') so a soft-deleted vehicle's plate is NOT treated as
            // taken — matches the live-only partial unique index (vehicle_plate_unique).
            'plate' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('vehicle', 'plate')->where('tenant_id', tenant('id'))->whereNull('deleted_at')->ignore($vehicleId)],
            'make' => ['sometimes', 'nullable', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
