<?php

namespace Modules\Housing\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitType;

/**
 * §6.3.3 — "No permitir crear unidad duplicada": friendly 422 BEFORE the partial
 * unique index unit_identity_unique would surface as a 500 QueryException.
 *
 * Mirrors the index's COALESCE semantics: NULL tower/floor/number compare as
 * equal, and only live rows count (TenantScope + SoftDeletes already restrict
 * the query to the current tenant's non-deleted units). The DB index stays as
 * the concurrency safety net.
 */
trait GuardsUnitIdentity
{
    /**
     * @param  array{unit_type_id: ?string, tower_id: ?string, floor: mixed, number: ?string}  $identity
     *
     * @throws ValidationException when another live unit already has this identity
     */
    protected function assertUnitIdentityIsUnique(array $identity, ?string $ignoreUnitId = null): void
    {
        if (($identity['unit_type_id'] ?? null) === null) {
            return;
        }

        $conflictExists = Unit::query()
            ->where('unit_type_id', $identity['unit_type_id'])
            ->where(fn (Builder $query) => $this->whereNullableEquals($query, 'tower_id', $identity['tower_id'] ?? null))
            ->where(fn (Builder $query) => $this->whereNullableEquals($query, 'floor', $identity['floor'] ?? null))
            ->where(fn (Builder $query) => $this->whereNullableEquals($query, 'number', $identity['number'] ?? null))
            ->when($ignoreUnitId, static fn (Builder $query, string $id) => $query->whereKeyNot($id))
            ->exists();

        if ($conflictExists) {
            throw ValidationException::withMessages([
                'number' => [$this->duplicateUnitMessage($identity)],
            ]);
        }
    }

    /**
     * Identity the entity would HAVE after a partial update: absent payload keys
     * fall back to the entity's current values.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{unit_type_id: ?string, tower_id: ?string, floor: mixed, number: ?string}
     */
    protected function effectiveUnitIdentity(Unit $unit, array $attributes): array
    {
        $identity = [];
        foreach (['unit_type_id', 'tower_id', 'floor', 'number'] as $field) {
            $identity[$field] = array_key_exists($field, $attributes)
                ? $attributes[$field]
                : $unit->{$field};
        }

        return $identity;
    }

    private function whereNullableEquals(Builder $query, string $column, mixed $value): Builder
    {
        return $value === null || $value === ''
            ? $query->whereNull($column)
            : $query->where($column, $value);
    }

    /**
     * Spec message §6.3.3: «Ya existe una Casa 23 en este clúster».
     *
     * @param  array{unit_type_id: ?string, tower_id: ?string, floor: mixed, number: ?string}  $identity
     */
    private function duplicateUnitMessage(array $identity): string
    {
        $typeName = UnitType::query()->find($identity['unit_type_id'])?->name ?? 'unidad';

        return sprintf('Ya existe una %s %s en este condominio.', $typeName, $identity['number'] ?? 's/n');
    }
}
