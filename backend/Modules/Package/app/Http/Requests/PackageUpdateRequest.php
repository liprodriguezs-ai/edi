<?php

namespace Modules\Package\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // unit_id excluded from free update — reassign has its own endpoint.
            'package_carrier_id' => ['nullable', 'uuid', Rule::exists('package_carrier', 'id')->where('tenant_id', tenant('id'))],
            'package_type_id' => ['nullable', 'uuid', Rule::exists('package_type', 'id')->where('tenant_id', tenant('id'))],
            // unit_id is not editable here, so scope the recipient to a member of the current
            // tenant (via user_tenant) rather than a specific unit.
            'recipient_user_id' => ['nullable', 'uuid', Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id'))],
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
