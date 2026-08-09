<?php

namespace Modules\Housing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Rules\OwnershipShareWithinLimit;

class UnitCreateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // unit_type / unit_status son catálogos tenant-scoped: el exists
            // debe acotarse al tenant para impedir referencias cross-tenant.
            'unit_type_id' => ['required', 'uuid', Rule::exists('unit_type', 'id')->where('tenant_id', tenant('id'))],
            // §6.3.3: tipos con requires_floor exigen torre + piso + número.
            'number' => [$this->requiredWhenTypeRequiresFloor(), 'nullable', 'string', 'max:50'],
            // Dirección mexicana: calle + número interior. Ambos opcionales y
            // ajenos a la identidad única de la unidad (tipo/torre/piso/número).
            'street' => ['nullable', 'string', 'max:150'],
            'interior_number' => ['nullable', 'string', 'max:50'],
            'tower_id' => [$this->requiredWhenTypeRequiresFloor(), 'nullable', 'uuid', Rule::exists('tower', 'id')->where('tenant_id', tenant('id'))],
            'floor' => [$this->requiredWhenTypeRequiresFloor(), 'nullable', 'string', 'max:20'],
            'section_id' => ['nullable', 'uuid', Rule::exists('section', 'id')->where('tenant_id', tenant('id'))],
            'built_m2' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'land_m2' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            // §6.3.3: the sum of ownership_share across active units must not exceed 100%.
            'ownership_share' => ['nullable', 'numeric', 'min:0', 'max:100', new OwnershipShareWithinLimit],
            'unit_status_id' => ['required', 'uuid', Rule::exists('unit_status', 'id')->where('tenant_id', tenant('id'))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'characteristics' => ['nullable', 'array'],
        ];
    }

    /**
     * 'required' cuando el tipo elegido tiene requires_floor (vertical:
     * torre/piso/número obligatorios).
     */
    private function requiredWhenTypeRequiresFloor(): string
    {
        $typeId = $this->input('unit_type_id');

        $requiresFloor = $typeId
            ? (bool) UnitType::query()->whereKey($typeId)->value('requires_floor')
            : false;

        return $requiresFloor ? 'required' : 'nullable';
    }

    public function authorize(): bool
    {
        return true;
    }
}
