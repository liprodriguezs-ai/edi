<?php

namespace Modules\Housing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitType;
use Modules\Housing\Rules\OwnershipShareWithinLimit;

class UnitUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $unitId = $this->resolveUnitId();

        return [
            // unit_type / unit_status son catálogos tenant-scoped: el exists
            // debe acotarse al tenant para impedir referencias cross-tenant.
            'unit_type_id' => ['sometimes', 'required', 'uuid', Rule::exists('unit_type', 'id')->where('tenant_id', tenant('id'))],
            // §6.3.3: tipos con requires_floor exigen torre + piso + número.
            'number' => ['sometimes', $this->requiredWhenTypeRequiresFloor(), 'nullable', 'string', 'max:50'],
            // Dirección mexicana: calle + número interior. Ambos opcionales y
            // ajenos a la identidad única de la unidad (tipo/torre/piso/número).
            'street' => ['sometimes', 'nullable', 'string', 'max:150'],
            'interior_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tower_id' => ['sometimes', $this->requiredWhenTypeRequiresFloor(), 'nullable', 'uuid', Rule::exists('tower', 'id')->where('tenant_id', tenant('id'))],
            'floor' => ['sometimes', $this->requiredWhenTypeRequiresFloor(), 'nullable', 'string', 'max:20'],
            'section_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('section', 'id')->where('tenant_id', tenant('id'))],
            'built_m2' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999.99'],
            'land_m2' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999.99'],
            // §6.3.3: the sum of ownership_share across active units must not exceed 100%.
            'ownership_share' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100', new OwnershipShareWithinLimit($unitId)],
            'unit_status_id' => ['sometimes', 'required', 'uuid', Rule::exists('unit_status', 'id')->where('tenant_id', tenant('id'))],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'characteristics' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * 'required' cuando el tipo EFECTIVO (payload o el actual de la unidad)
     * tiene requires_floor (vertical: torre/piso/número obligatorios).
     */
    private function requiredWhenTypeRequiresFloor(): string
    {
        $typeId = $this->input('unit_type_id') ?? $this->existingUnit()?->unit_type_id;

        $requiresFloor = $typeId
            ? (bool) UnitType::query()->whereKey($typeId)->value('requires_floor')
            : false;

        return $requiresFloor ? 'required' : 'nullable';
    }

    private function resolveUnitId(): ?string
    {
        $route = $this->route('unit') ?? $this->route('id');

        return $route instanceof Unit ? $route->id : (is_string($route) ? $route : null);
    }

    private function existingUnit(): ?Unit
    {
        $id = $this->resolveUnitId();

        return $id ? Unit::find($id) : null;
    }

    public function authorize(): bool
    {
        return true;
    }
}
