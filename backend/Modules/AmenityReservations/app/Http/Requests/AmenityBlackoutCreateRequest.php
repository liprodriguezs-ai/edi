<?php

namespace Modules\AmenityReservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Date range during which an amenity cannot be reserved (§S4).
 */
class AmenityBlackoutCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // amenity is TENANT-scoped → exists must be scoped per tenant.
            'amenity_id' => ['required', 'uuid', Rule::exists('amenity', 'id')->where('tenant_id', tenant('id'))->whereNull('deleted_at')],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'La fecha de fin debe ser posterior o igual a la fecha de inicio.',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
