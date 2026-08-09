<?php

namespace Modules\Announcements\Services;

use Modules\Housing\Models\Section;
use Modules\Housing\Models\Tower;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitFeature;
use Modules\Housing\Models\UnitType;

/**
 * AnnouncementAudienceLabeler — turns a (target_type, target_selector) pair into a
 * human-readable Spanish audience label for the "announcement published" email
 * ("Enviado a: …").
 *
 * It ALWAYS returns a non-empty string: the email template must never render a blank
 * audience line, and an unknown or degraded selector falls back to "Todo el condominio"
 * instead of throwing.
 *
 * Tenant-safe: every catalog lookup (Tower / UnitType / UnitFeature / Section / Unit)
 * goes through the tenant-scoped Eloquent models, so it must run inside a tenant
 * context — publish() already dispatches inside one.
 */
class AnnouncementAudienceLabeler
{
    /**
     * @param  array<string, mixed>|null  $selector
     */
    public function label(string $targetType, ?array $selector): string
    {
        $selector ??= [];

        return match ($targetType) {
            'all' => 'Todo el condominio',
            'board' => 'Solo la mesa directiva',
            'with_vehicles' => 'Unidades con vehículo',
            'manual' => $this->manualLabel($selector),
            'single_unit' => $this->singleUnitLabel($selector),
            'by_floor' => $this->floorLabel($selector),
            'by_type' => $this->namedLabel('Por tipo', UnitType::class, $selector['unit_type_ids'] ?? []),
            'by_tower' => $this->namedLabel('Por torre', Tower::class, $selector['tower_ids'] ?? []),
            'by_section' => $this->namedLabel('Por sección', Section::class, $selector['section_ids'] ?? []),
            'by_feature' => $this->namedLabel('Por característica', UnitFeature::class, $selector['unit_feature_ids'] ?? []),
            default => 'Todo el condominio',
        };
    }

    /**
     * "N unidades seleccionadas" from selector.unit_ids[].
     *
     * @param  array<string, mixed>  $selector
     */
    private function manualLabel(array $selector): string
    {
        $count = count($selector['unit_ids'] ?? []);

        if ($count === 0) {
            return 'Unidades seleccionadas';
        }

        return $count === 1 ? '1 unidad seleccionada' : "{$count} unidades seleccionadas";
    }

    /**
     * The single unit's human identifier (e.g. "Casa 23" / "Torre Norte · Piso 5 · Depto 502").
     *
     * @param  array<string, mixed>  $selector
     */
    private function singleUnitLabel(array $selector): string
    {
        $unitId = $selector['unit_id'] ?? null;

        if (! $unitId) {
            return 'Una unidad';
        }

        $unit = Unit::with(['unitType', 'tower'])->find($unitId);

        return $unit?->full_identifier ?? 'Una unidad';
    }

    /**
     * "Piso 5" / "Pisos 3, 5" from selector.floors[].
     *
     * @param  array<string, mixed>  $selector
     */
    private function floorLabel(array $selector): string
    {
        $floors = array_values(array_filter(
            $selector['floors'] ?? [],
            static fn ($floor): bool => $floor !== null && $floor !== '',
        ));

        if ($floors === []) {
            return 'Por piso';
        }

        $prefix = count($floors) === 1 ? 'Piso ' : 'Pisos ';

        return $prefix.implode(', ', $floors);
    }

    /**
     * "Por torre: Norte, Sur" — expands the selector ids to their catalog names.
     * Degrades to the bare prefix when no ids resolve (never a blank label).
     *
     * @param  class-string  $model
     * @param  array<int, string>  $ids
     */
    private function namedLabel(string $prefix, string $model, array $ids): string
    {
        if ($ids === []) {
            return $prefix;
        }

        $names = $model::whereIn('id', $ids)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        if ($names === []) {
            return $prefix;
        }

        return $prefix.': '.implode(', ', $names);
    }
}
