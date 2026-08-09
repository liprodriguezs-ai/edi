<?php

namespace Modules\Package\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageBatchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'packages' => ['required', 'array', 'min:1', 'max:50'],
            'packages.*.unit_id' => ['required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id'))],
            'packages.*.package_carrier_id' => ['nullable', 'uuid', Rule::exists('package_carrier', 'id')->where('tenant_id', tenant('id'))],
            'packages.*.package_type_id' => ['nullable', 'uuid', Rule::exists('package_type', 'id')->where('tenant_id', tenant('id'))],
            // A static wildcard rule can't reference the sibling row's unit_id, so scope the
            // recipient to a member of the current tenant (via user_tenant).
            'packages.*.recipient_user_id' => ['nullable', 'uuid', Rule::exists('user_tenant', 'user_id')->where('tenant_id', tenant('id'))],
            'packages.*.tracking_number' => ['nullable', 'string', 'max:100'],
            'packages.*.description' => ['nullable', 'string', 'max:500'],
            'packages.*.storage_location' => ['nullable', 'string', 'max:100'],
            'packages.*.carrier_other' => ['nullable', 'string', 'max:100'],
            'packages.*.recipient_name' => ['nullable', 'string', 'max:150'],
            'packages.*.photo' => ['nullable', 'image', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
