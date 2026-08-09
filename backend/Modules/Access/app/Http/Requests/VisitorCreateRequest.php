<?php

namespace Modules\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Access\Models\QrType;
use Modules\Access\Rules\CallerBelongsToUnit;

class VisitorCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // unit is TENANT-scoped: single shared DB, the FK alone does not stop a
            // payload pointing at another cluster's unit. bail: the membership rule
            // only runs once the unit is a valid unit of THIS tenant.
            'unit_id' => ['bail', 'required', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id')), new CallerBelongsToUnit],
            'name' => ['required', 'string', 'max:255'],
            // visitor_motive is TENANT-scoped (has tenant_id); qr_type is CENTRAL (no tenant_id).
            'visitor_motive_id' => ['required', 'uuid', Rule::exists('visitor_motive', 'id')->where('tenant_id', tenant('id'))],
            'qr_type_id' => ['required', 'uuid', Rule::exists('qr_type', 'id')],
            'arrives_by_vehicle' => ['nullable', 'boolean'],
            'plate' => ['nullable', 'required_if:arrives_by_vehicle,true', 'string', 'max:20'],
            'vehicle_model' => ['nullable', 'string', 'max:100'],
            'id_document_type' => ['nullable', 'string', Rule::in(['ine', 'passport', 'driver_license', 'other'])],
            'id_document_number' => ['nullable', 'string', 'max:100'],
            // §14.5 r2: an EVENT QR is only valid within a specific window — without
            // an expiry it would be an eternal multi-use QR.
            'qr_expires_at' => [Rule::requiredIf($this->input('qr_type_id') === QrType::EVENT), 'nullable', 'date', 'after:now'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
