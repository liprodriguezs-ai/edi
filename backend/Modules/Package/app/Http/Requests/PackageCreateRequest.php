<?php

namespace Modules\Package\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // unit_id is TENANT-scoped: the exists check restricts to the current tenant's units.
            'unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))],
            'package_carrier_id' => ['nullable', 'uuid', Rule::exists('package_carrier', 'id')->where('tenant_id', tenant('id'))],
            'package_type_id' => ['nullable', 'uuid', Rule::exists('package_type', 'id')->where('tenant_id', tenant('id'))],
            // recipient must be an ACTIVE resident of the target unit (unit_user without end_date).
            'recipient_user_id' => ['nullable', 'uuid', Rule::exists('unit_user', 'user_id')->where('unit_id', $this->input('unit_id'))->whereNull('end_date')],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'storage_location' => ['nullable', 'string', 'max:100'],
            'carrier_other' => ['nullable', 'string', 'max:100'],
            'recipient_name' => ['nullable', 'string', 'max:150'],
            'photo' => ['nullable', 'image', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
