<?php

namespace Modules\Access\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Access\Rules\CallerBelongsToUnit;

class VisitorUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // unit is TENANT-scoped: bail ensures CallerBelongsToUnit only runs once the
            // unit exists in THIS tenant. Mirrors the Create request membership check.
            'unit_id' => ['bail', 'sometimes', 'uuid', Rule::exists('unit', 'id')->where('tenant_id', tenant('id')), new CallerBelongsToUnit],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'visitor_motive_id' => ['sometimes', 'uuid', Rule::exists('visitor_motive', 'id')->where('tenant_id', tenant('id'))],
            'arrives_by_vehicle' => ['sometimes', 'boolean'],
            'plate' => ['nullable', 'string', 'max:20'],
            'vehicle_model' => ['nullable', 'string', 'max:100'],
            'id_document_type' => ['nullable', 'string', Rule::in(['ine', 'passport', 'driver_license', 'other'])],
            'id_document_number' => ['nullable', 'string', 'max:100'],
            // qr_expires_at is intentionally excluded: QR validity must not be silently
            // extended post-creation. Extension requires a staff action (cancel + reissue).
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
