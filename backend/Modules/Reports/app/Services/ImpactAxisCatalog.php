<?php

namespace Modules\Reports\Services;

use Modules\Instrument\Models\DevelopmentArea;

/**
 * Columns of the "área o eje de impacto" block of the Concentrado EDI, read
 * from the `development_area` catalog so a new or renamed area never needs a
 * code change. The biological risk factors (FRB) are not a catalog area: they
 * come first, as in the legacy report.
 */
class ImpactAxisCatalog
{
    public const RISK_FACTORS_CODE = 'FRB';

    /** @var list<array{code: string, name: string, area_id: ?string}>|null */
    private ?array $axes = null;

    /**
     * @return list<array{code: string, name: string, area_id: ?string}>
     */
    public function axes(): array
    {
        return $this->axes ??= [
            ['code' => self::RISK_FACTORS_CODE, 'name' => 'Factores de riesgo biológico', 'area_id' => null],
            ...DevelopmentArea::query()
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name'])
                ->map(fn (DevelopmentArea $area): array => ['code' => $area->code, 'name' => $area->name, 'area_id' => $area->id])
                ->all(),
        ];
    }

    /**
     * The axes as the API exposes them (no internal ids).
     *
     * @return list<array{code: string, name: string}>
     */
    public function publicAxes(): array
    {
        return array_map(fn (array $axis): array => ['code' => $axis['code'], 'name' => $axis['name']], $this->axes());
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_column($this->axes(), 'code');
    }
}
