<?php

namespace Modules\Collections\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitStatus;

/**
 * TargetResolver maps a (target_type, target_selector) pair to a collection of Units.
 *
 * target_type values:
 *   all           — every active unit in the tenant
 *   by_type       — units whose unit_type_id is in selector.unit_type_ids[]
 *   by_tower      — units whose tower_id is in selector.tower_ids[]
 *   by_floor      — units in selector.tower_ids[] AND floor in selector.floors[]
 *   manual        — units whose id is in selector.unit_ids[]
 *   by_feature    — units that have ALL feature ids in selector.unit_feature_ids[]
 *   by_share      — all active units (proration is handled by AmountCalculator)
 *   single_unit   — exactly the unit in selector.unit_id
 *   by_section    — units whose OWN section_id is in selector.section_ids[] (a unit
 *                   whose tower belongs to the section but has no section_id of its
 *                   own is NOT included — never inherited from the tower)
 *   with_vehicles — billable units with at least one (non-soft-deleted) vehicle; no
 *                   selector payload (travels null, like 'all')
 *
 * BILLABILITY (spec §6.5 r3): an INACTIVE unit receives NO new charges but keeps its
 * history. Every resolver method excludes units whose unit_status_id is
 * UnitStatus::INACTIVE — including manual/single_unit, where an explicitly-targeted
 * inactive unit is skipped silently. Vacant / under_construction units REMAIN billable
 * (the spec excludes only "inactiva").
 */
class TargetResolver
{
    /**
     * The closed set of valid target types (spec §8.4.2).
     *
     * @var string[]
     */
    public const TARGET_TYPES = [
        'all', 'by_type', 'by_tower', 'by_floor', 'manual', 'by_feature', 'by_share', 'single_unit',
        'by_section', 'with_vehicles',
    ];

    /**
     * Resolve target to a collection of Unit models.
     *
     * @param  array<string, mixed>|null  $selector
     * @return EloquentCollection<int, Unit>
     *
     * @throws \InvalidArgumentException On an unknown target_type — NEVER silently
     *                                   falls back to ALL units (a typo must not
     *                                   charge the whole cluster, finding collections-12).
     */
    public function resolve(string $targetType, ?array $selector): EloquentCollection
    {
        return match ($targetType) {
            'all', 'by_share' => $this->allActive(),
            'by_type' => $this->byType($selector['unit_type_ids'] ?? []),
            'by_tower' => $this->byTower($selector['tower_ids'] ?? []),
            'by_floor' => $this->byFloor($selector['tower_ids'] ?? [], $selector['floors'] ?? []),
            'manual' => $this->manual($selector['unit_ids'] ?? []),
            'by_feature' => $this->byFeature($selector['unit_feature_ids'] ?? []),
            'single_unit' => $this->singleUnit($selector['unit_id'] ?? null),
            'by_section' => $this->bySection($selector['section_ids'] ?? []),
            'with_vehicles' => $this->withVehicles(),
            default => throw new \InvalidArgumentException("Alcance de cobro desconocido: {$targetType}."),
        };
    }

    /**
     * Preview count of units that would be targeted. Cheap query.
     */
    public function previewCount(string $targetType, ?array $selector): int
    {
        return $this->resolve($targetType, $selector)->count();
    }

    /**
     * Resolve target to a lightweight Collection of unit IDs, WITHOUT hydrating full
     * Unit models. Mirrors resolve() arm-for-arm (same billability filter, same
     * 'all'/'by_share' short-circuits) but issues `select "id"` via pluck — for callers
     * that only need ids (e.g. announcement audience preview) so they never materialize
     * N Unit models just to read their ids. Does NOT touch resolve(), which keeps
     * returning full models for charge generation.
     *
     * @param  array<string, mixed>|null  $selector
     * @return Collection<int, string>
     *
     * @throws \InvalidArgumentException On an unknown target_type (same contract as resolve()).
     */
    public function resolveUnitIds(string $targetType, ?array $selector): Collection
    {
        return match ($targetType) {
            'all', 'by_share' => $this->billableQuery()->pluck('id'),
            'by_type' => $this->billableQuery()->whereIn('unit_type_id', $selector['unit_type_ids'] ?? [])->pluck('id'),
            'by_tower' => $this->billableQuery()->whereIn('tower_id', $selector['tower_ids'] ?? [])->pluck('id'),
            'by_floor' => $this->billableQuery()
                ->when(! empty($selector['tower_ids'] ?? []), fn (Builder $q) => $q->whereIn('tower_id', $selector['tower_ids']))
                ->whereIn('floor', $selector['floors'] ?? [])
                ->pluck('id'),
            'manual' => $this->billableQuery()->whereIn('id', $selector['unit_ids'] ?? [])->pluck('id'),
            'by_feature' => $this->featureUnitIds($selector['unit_feature_ids'] ?? []),
            'single_unit' => $this->singleUnitIds($selector['unit_id'] ?? null),
            'by_section' => $this->billableQuery()->whereIn('section_id', $selector['section_ids'] ?? [])->pluck('id'),
            'with_vehicles' => $this->billableQuery()->whereHas('vehicles')->pluck('id'),
            default => throw new \InvalidArgumentException("Alcance de cobro desconocido: {$targetType}."),
        };
    }

    /**
     * Id-only mirror of byFeature(): units having ALL requested features (intersection).
     *
     * @param  string[]  $featureIds
     * @return Collection<int, string>
     */
    private function featureUnitIds(array $featureIds): Collection
    {
        if (empty($featureIds)) {
            return $this->billableQuery()->pluck('id');
        }

        return $this->billableQuery()
            ->whereHas('features', function ($q) use ($featureIds): void {
                $q->whereIn('unit_feature_id', $featureIds);
            }, '>=', count($featureIds))
            ->pluck('id');
    }

    /**
     * Id-only mirror of singleUnit(): the one billable unit, or an empty Collection
     * when no id is supplied.
     *
     * @return Collection<int, string>
     */
    private function singleUnitIds(?string $unitId): Collection
    {
        if (! $unitId) {
            return new Collection;
        }

        return $this->billableQuery()->where('id', $unitId)->pluck('id');
    }

    private function allActive(): EloquentCollection
    {
        return $this->billableQuery()->get();
    }

    /**
     * @param  string[]  $unitTypeIds
     */
    private function byType(array $unitTypeIds): EloquentCollection
    {
        return $this->billableQuery()
            ->whereIn('unit_type_id', $unitTypeIds)
            ->get();
    }

    /**
     * @param  string[]  $towerIds
     */
    private function byTower(array $towerIds): EloquentCollection
    {
        return $this->billableQuery()
            ->whereIn('tower_id', $towerIds)
            ->get();
    }

    /**
     * Units whose OWN section_id is in the given list. A unit whose tower
     * belongs to the section but has no section_id of its own is NOT
     * included — section_id is read directly off the unit, never inherited
     * from the tower (explicit product decision). Mirrors byTower(): an
     * empty/absent selector resolves to zero units, no exception.
     *
     * @param  string[]  $sectionIds
     */
    private function bySection(array $sectionIds): EloquentCollection
    {
        return $this->billableQuery()
            ->whereIn('section_id', $sectionIds)
            ->get();
    }

    /**
     * @param  string[]  $towerIds
     * @param  string[]  $floors
     */
    private function byFloor(array $towerIds, array $floors): EloquentCollection
    {
        // Tower is optional: when no tower is given, the floor applies across ALL
        // towers (the charge form only collects the floor number). When towers are
        // provided, the floor is scoped to those towers.
        return $this->billableQuery()
            ->when(! empty($towerIds), fn ($q) => $q->whereIn('tower_id', $towerIds))
            ->whereIn('floor', $floors)
            ->get();
    }

    /**
     * @param  string[]  $unitIds
     */
    private function manual(array $unitIds): EloquentCollection
    {
        return $this->billableQuery()
            ->whereIn('id', $unitIds)
            ->get();
    }

    /**
     * Units that have ALL of the requested features (intersection, not union).
     *
     * @param  string[]  $featureIds
     */
    private function byFeature(array $featureIds): EloquentCollection
    {
        if (empty($featureIds)) {
            return $this->allActive();
        }

        return $this->billableQuery()
            ->whereHas('features', function ($q) use ($featureIds): void {
                $q->whereIn('unit_feature_id', $featureIds);
            }, '>=', count($featureIds))
            ->get();
    }

    /**
     * Billable units that have at least ONE vehicle. Vehicle uses SoftDeletes
     * (via BaseModel), so whereHas already excludes soft-deleted vehicles —
     * no explicit whereNull('deleted_at') needed on the relation.
     */
    private function withVehicles(): EloquentCollection
    {
        return $this->billableQuery()
            ->whereHas('vehicles')
            ->get();
    }

    private function singleUnit(?string $unitId): EloquentCollection
    {
        if (! $unitId) {
            return new EloquentCollection;
        }

        return $this->billableQuery()
            ->where('id', $unitId)
            ->get();
    }

    /**
     * Base query for billable units: not soft-deleted and whose status has
     * is_billable=true. Non-billable units (e.g. "Inactiva") keep their history
     * but receive no new charges (spec §6.5 r3).
     *
     * @return Builder<Unit>
     */
    private function billableQuery(): Builder
    {
        return Unit::query()
            ->whereNull('deleted_at')
            ->where(function (Builder $q): void {
                $q->whereNull('unit_status_id')
                    ->orWhereHas('unitStatus', function (Builder $status): void {
                        $status->where('is_billable', true);
                    });
            });
    }
}
