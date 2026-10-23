<?php

namespace Modules\Reports\Services;

use Illuminate\Support\Carbon;
use Modules\Clinic\Models\HealthCenter;
use Modules\Reports\Models\ScreeningIndicatorSnapshot;
use QCH\Core\Services\BuildsTenantReports;
use QCH\Core\Support\TenantTime;

/**
 * Builds/upserts screening_indicator_snapshot rows for a calendar month
 * (design D1/D6, REQ-RPT-002/003). Snapshot writes are service-only (D14) --
 * this is the ONLY writer of the table.
 */
class IndicatorSnapshotService
{
    use BuildsTenantReports;

    public function __construct(private readonly IndicatorAggregationQuery $aggregation) {}

    /**
     * Upserts exactly one row per tenant health center PLUS the
     * whole-organization rollup row, even when a center had zero screenings
     * that month (REQ-RPT-001 scenario: N centers => N+1 rows, always).
     * Idempotent: an unchanged rerun updates only `computed_at`.
     */
    public function buildPeriod(string $period): void
    {
        $periodStart = Carbon::createFromFormat('!Y-m', $period)->startOfMonth();
        $periodEnd = (clone $periodStart)->endOfMonth();

        $rowsByGrain = $this->zeroedRowsByGrain();

        foreach ($this->aggregation->forPeriod($periodStart, $periodEnd) as $row) {
            $grain = $row['health_center_id'] ?? self::ROLLUP_KEY;
            unset($row['health_center_id']);
            $rowsByGrain[$grain] = $row;
        }

        $computedAt = now();

        foreach ($rowsByGrain as $grain => $counters) {
            ScreeningIndicatorSnapshot::query()->updateOrCreate(
                [
                    'health_center_id' => $grain === self::ROLLUP_KEY ? null : $grain,
                    'period_start' => $periodStart->toDateString(),
                ],
                [...$counters, 'computed_at' => $computedAt]
            );
        }
    }

    /**
     * Trailing window of `$months` ending at (and including) the current
     * calendar month (D6/REQ-RPT-003 default).
     */
    public function buildTrailingWindow(int $months): void
    {
        $current = Carbon::createFromFormat('!Y-m', TenantTime::currentMonth());

        for ($i = $months - 1; $i >= 0; $i--) {
            $this->buildPeriod($current->copy()->subMonths($i)->format('Y-m'));
        }
    }

    private const ROLLUP_KEY = '__rollup__';

    /**
     * @return array<string, array<string, mixed>>
     */
    private function zeroedRowsByGrain(): array
    {
        $zero = [
            'screenings_applied' => 0,
            'screenings_completed' => 0,
            'children_screened' => 0,
            'result_green_count' => 0,
            'result_yellow_count' => 0,
            'result_red_count' => 0,
            'first_time_count' => 0,
            'alert_sign_screening_count' => 0,
            'risk_factor_screening_count' => 0,
            'confirmatory_pending_count' => 0,
            'area_breakdown' => [],
            'situation_breakdown' => [],
        ];

        $rows = [self::ROLLUP_KEY => $zero];

        foreach (HealthCenter::query()->pluck('id') as $healthCenterId) {
            $rows[$healthCenterId] = $zero;
        }

        return $rows;
    }
}
