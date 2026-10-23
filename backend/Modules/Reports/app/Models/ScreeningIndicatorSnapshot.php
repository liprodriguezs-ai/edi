<?php

namespace Modules\Reports\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Clinic\Models\HealthCenter;
use Modules\Instrument\Models\DevelopmentArea;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Materialized screening indicator aggregates per tenant, health center
 * (NULL = whole organization), and calendar month (design §3, D1/D2).
 *
 * Derived truth: writes are service-only via
 * IndicatorSnapshotService::buildPeriod (D14) using updateOrCreate against
 * the unique (tenant_id, health_center_id, period_start) grain — no write
 * endpoint exists for this table.
 */
#[ObservedBy([GlobalObserver::class])]
class ScreeningIndicatorSnapshot extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'screening_indicator_snapshot';

    protected $fillable = [
        'health_center_id',
        'period_start',
        'screenings_applied',
        'screenings_completed',
        'children_screened',
        'result_green_count',
        'result_yellow_count',
        'result_red_count',
        'first_time_count',
        'alert_sign_screening_count',
        'risk_factor_screening_count',
        'confirmatory_pending_count',
        'area_breakdown',
        'situation_breakdown',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'screenings_applied' => 'integer',
            'screenings_completed' => 'integer',
            'children_screened' => 'integer',
            'result_green_count' => 'integer',
            'result_yellow_count' => 'integer',
            'result_red_count' => 'integer',
            'first_time_count' => 'integer',
            'alert_sign_screening_count' => 'integer',
            'risk_factor_screening_count' => 'integer',
            'confirmatory_pending_count' => 'integer',
            'area_breakdown' => 'array',
            'situation_breakdown' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function health_center(): BelongsTo
    {
        return $this->belongsTo(HealthCenter::class, 'health_center_id');
    }

    /**
     * REQ-RPT-007 — Spanish headers for the concentrate xlsx export
     * (ScreeningIndicator map key -> this model). Every closure reads only
     * columns/relations already present on the eager-loaded row (D9).
     */
    public function reportColumns(): array
    {
        return [
            'Periodo' => fn ($r) => $r->period_start ? $r->period_start->format('Y-m') : '',
            'Centro de salud' => fn ($r) => $r->health_center?->name ?? 'Toda la organización',
            'Pruebas EDI aplicadas' => fn ($r) => $r->screenings_applied,
            'Pruebas EDI completadas' => fn ($r) => $r->screenings_completed,
            'Niños evaluados' => fn ($r) => $r->children_screened,
            'Verde' => fn ($r) => $r->result_green_count,
            'Amarillo' => fn ($r) => $r->result_yellow_count,
            'Rojo' => fn ($r) => $r->result_red_count,
            'Primera vez' => fn ($r) => $r->first_time_count,
            'Con señales de alerta' => fn ($r) => $r->alert_sign_screening_count,
            'Con factores de riesgo' => fn ($r) => $r->risk_factor_screening_count,
            'Confirmatoria pendiente' => fn ($r) => $r->confirmatory_pending_count,
            ...self::areaColumns(),
            'Calculado' => fn ($r) => $r->computed_at ? $r->computed_at->format('d/m/Y H:i') : '',
        ];
    }

    /**
     * One "<code> (amarillo/rojo)" column per development axis of the
     * `development_area` catalog, in catalog order.
     *
     * @return array<string, \Closure>
     */
    private static function areaColumns(): array
    {
        return DevelopmentArea::query()
            ->where('is_development_axis', true)
            ->orderBy('sort_order')
            ->pluck('code')
            ->mapWithKeys(fn (string $code): array => [
                "{$code} (amarillo/rojo)" => fn ($r) => self::areaNotGreen($r->area_breakdown, $code),
            ])
            ->all();
    }

    private static function areaNotGreen(?array $areaBreakdown, string $code): int
    {
        $area = $areaBreakdown[$code] ?? null;

        if ($area === null) {
            return 0;
        }

        return (int) ($area['yellow'] ?? 0) + (int) ($area['red'] ?? 0);
    }
}
