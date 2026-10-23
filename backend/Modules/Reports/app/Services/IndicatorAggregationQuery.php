<?php

namespace Modules\Reports\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Instrument\Models\ResultColor;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningStatus;

/**
 * The SINGLE source of truth for "what happened in this period" (design D5).
 * Both IndicatorSnapshotService (builder, closed months) and
 * IndicatorReportService (live current month) consume this class -- two
 * implementations of "the month's numbers" would diverge.
 *
 * Every method is a set-based grouped query over the counted set (D3):
 * non-invalidated screenings whose visit.visit_date falls in the period AND
 * whose result_color_id is NOT NULL. Center-grain rows and the
 * whole-organization rollup come from the SAME result set via PostgreSQL
 * `GROUPING SETS` -- never a PHP loop summing per-center rows (REQ-RPT-002).
 *
 * `TenantScope` applies automatically through the `Screening::query()`
 * Eloquent builder (BelongsToTenant); this class MUST be called only under
 * initialized tenancy (D6/REQ-RPT-009) -- it never queries unscoped.
 */
class IndicatorAggregationQuery
{
    /**
     * One row per grain: health_center_id (string) for a center, or NULL for
     * the whole-organization rollup.
     *
     * @return array<int, array{
     *   health_center_id: ?string,
     *   screenings_applied: int,
     *   screenings_completed: int,
     *   children_screened: int,
     *   result_green_count: int,
     *   result_yellow_count: int,
     *   result_red_count: int,
     *   first_time_count: int,
     *   alert_sign_screening_count: int,
     *   risk_factor_screening_count: int,
     *   confirmatory_pending_count: int,
     *   area_breakdown: array<string, array{green: int, yellow: int, red: int}>,
     *   situation_breakdown: array<string, int>,
     * }>
     */
    public function forPeriod(Carbon $periodStart, Carbon $periodEnd): array
    {
        $totals = $this->totalsAndColors($periodStart, $periodEnd);
        $situations = $this->situationCounts($periodStart, $periodEnd);
        $areas = $this->areaColorCounts($periodStart, $periodEnd);
        $confirmatoryPending = $this->confirmatoryPendingCounts($periodStart, $periodEnd);

        $grains = array_keys($totals);
        $rows = [];

        foreach ($grains as $grain) {
            $totalsRow = $totals[$grain];
            $situationBreakdown = $situations[$grain] ?? [];

            $rows[] = [
                'health_center_id' => $grain === '' ? null : $grain,
                'screenings_applied' => $totalsRow['screenings_applied'],
                'screenings_completed' => $totalsRow['screenings_completed'],
                'children_screened' => $totalsRow['children_screened'],
                'result_green_count' => $totalsRow['green'],
                'result_yellow_count' => $totalsRow['yellow'],
                'result_red_count' => $totalsRow['red'],
                'first_time_count' => $this->sumFirstTime($situationBreakdown),
                'alert_sign_screening_count' => $totalsRow['alert_sign_screening_count'],
                'risk_factor_screening_count' => $totalsRow['risk_factor_screening_count'],
                'confirmatory_pending_count' => $confirmatoryPending[$grain] ?? 0,
                'area_breakdown' => $areas[$grain] ?? [],
                'situation_breakdown' => $situationBreakdown,
            ];
        }

        return $rows;
    }

    /**
     * Base counted-set query (D3): non-invalidated, with a result, in the
     * visit_date period. Joined to `visit` for health_center_id and dating.
     */
    private function countedSet(Carbon $periodStart, Carbon $periodEnd): Builder
    {
        return Screening::query()
            ->join('visit', 'visit.id', '=', 'screening.visit_id')
            ->whereNull('visit.deleted_at')
            ->whereBetween('visit.visit_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->where('screening.screening_status_id', '!=', ScreeningStatus::INVALIDATED)
            ->whereNotNull('screening.result_color_id');
    }

    /**
     * @return array<string, array{
     *   screenings_applied: int, screenings_completed: int, children_screened: int,
     *   green: int, yellow: int, red: int,
     *   alert_sign_screening_count: int, risk_factor_screening_count: int,
     * }>
     */
    private function totalsAndColors(Carbon $periodStart, Carbon $periodEnd): array
    {
        $rows = $this->countedSet($periodStart, $periodEnd)
            ->selectRaw('visit.health_center_id as grain_health_center_id')
            ->selectRaw('COUNT(*) as screenings_applied')
            ->selectRaw('SUM(CASE WHEN screening.screening_status_id = ? THEN 1 ELSE 0 END) as screenings_completed', [ScreeningStatus::COMPLETED])
            ->selectRaw('COUNT(DISTINCT screening.child_id) as children_screened')
            ->selectRaw('SUM(CASE WHEN screening.result_color_id = ? THEN 1 ELSE 0 END) as green', [ResultColor::GREEN])
            ->selectRaw('SUM(CASE WHEN screening.result_color_id = ? THEN 1 ELSE 0 END) as yellow', [ResultColor::YELLOW])
            ->selectRaw('SUM(CASE WHEN screening.result_color_id = ? THEN 1 ELSE 0 END) as red', [ResultColor::RED])
            ->selectRaw('SUM(CASE WHEN screening.alert_sign_count > 0 THEN 1 ELSE 0 END) as alert_sign_screening_count')
            ->selectRaw('SUM(CASE WHEN screening.risk_factor_count > 0 THEN 1 ELSE 0 END) as risk_factor_screening_count')
            ->groupByRaw('GROUPING SETS ((visit.health_center_id), ())')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $grain = $row->grain_health_center_id ?? '';
            $result[$grain] = [
                'screenings_applied' => (int) $row->screenings_applied,
                'screenings_completed' => (int) $row->screenings_completed,
                'children_screened' => (int) $row->children_screened,
                'green' => (int) $row->green,
                'yellow' => (int) $row->yellow,
                'red' => (int) $row->red,
                'alert_sign_screening_count' => (int) $row->alert_sign_screening_count,
                'risk_factor_screening_count' => (int) $row->risk_factor_screening_count,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array<string, int>> grain => [situation_key => count]
     */
    private function situationCounts(Carbon $periodStart, Carbon $periodEnd): array
    {
        $rows = $this->countedSet($periodStart, $periodEnd)
            ->join('screening_situation', 'screening_situation.id', '=', 'screening.situation_id')
            ->selectRaw('visit.health_center_id as grain_health_center_id')
            ->selectRaw('screening_situation.key as situation_key')
            ->selectRaw('COUNT(*) as cnt')
            ->groupByRaw('GROUPING SETS ((visit.health_center_id, screening_situation.key), (screening_situation.key))')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $grain = $row->grain_health_center_id ?? '';
            $result[$grain][$row->situation_key] = (int) $row->cnt;
        }

        return $result;
    }

    /**
     * @return array<string, array<string, array{green: int, yellow: int, red: int}>>
     */
    private function areaColorCounts(Carbon $periodStart, Carbon $periodEnd): array
    {
        $rows = $this->countedSet($periodStart, $periodEnd)
            ->join('screening_area_result', 'screening_area_result.screening_id', '=', 'screening.id')
            ->join('development_area', 'development_area.id', '=', 'screening_area_result.development_area_id')
            ->whereNull('screening_area_result.deleted_at')
            ->whereNotNull('screening_area_result.result_color_id')
            ->selectRaw('visit.health_center_id as grain_health_center_id')
            ->selectRaw('development_area.code as area_code')
            ->selectRaw('screening_area_result.result_color_id as result_color_id')
            ->selectRaw('COUNT(*) as cnt')
            ->groupByRaw('GROUPING SETS ((visit.health_center_id, development_area.code, screening_area_result.result_color_id), (development_area.code, screening_area_result.result_color_id))')
            ->get();

        $colorKeyByUuid = [
            ResultColor::GREEN => 'green',
            ResultColor::YELLOW => 'yellow',
            ResultColor::RED => 'red',
        ];

        $result = [];
        foreach ($rows as $row) {
            $grain = $row->grain_health_center_id ?? '';
            $colorKey = $colorKeyByUuid[$row->result_color_id] ?? null;

            if ($colorKey === null) {
                continue;
            }

            $result[$grain][$row->area_code] ??= ['green' => 0, 'yellow' => 0, 'red' => 0];
            $result[$grain][$row->area_code][$colorKey] = (int) $row->cnt;
        }

        return $result;
    }

    /**
     * Red completed screenings without a CONFIRMED confirmatory test result
     * (no confirmatory_test row at all, or one recorded without a result
     * yet -- both are "pending" from the indicator's point of view).
     *
     * @return array<string, int>
     */
    private function confirmatoryPendingCounts(Carbon $periodStart, Carbon $periodEnd): array
    {
        $rows = $this->countedSet($periodStart, $periodEnd)
            ->where('screening.screening_status_id', ScreeningStatus::COMPLETED)
            ->where('screening.result_color_id', ResultColor::RED)
            ->leftJoin('confirmatory_test', function ($join) {
                $join->on('confirmatory_test.screening_id', '=', 'screening.id')
                    ->whereNull('confirmatory_test.deleted_at')
                    ->whereNotNull('confirmatory_test.confirmatory_test_result_id');
            })
            ->whereNull('confirmatory_test.id')
            ->selectRaw('visit.health_center_id as grain_health_center_id')
            ->selectRaw('COUNT(*) as cnt')
            ->groupByRaw('GROUPING SETS ((visit.health_center_id), ())')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $grain = $row->grain_health_center_id ?? '';
            $result[$grain] = (int) $row->cnt;
        }

        return $result;
    }

    /**
     * @param  array<string, int>  $situationBreakdown
     */
    private function sumFirstTime(array $situationBreakdown): int
    {
        $sum = 0;
        foreach ($situationBreakdown as $key => $count) {
            if (str_starts_with($key, 'first_time_')) {
                $sum += $count;
            }
        }

        return $sum;
    }
}
