<?php

namespace Modules\Reports\Services;

use Illuminate\Support\Carbon;
use Modules\Reports\Models\ScreeningIndicatorSnapshot;
use QCH\Core\Services\BuildsTenantReports;
use QCH\Core\Services\CachesTenantReports;
use QCH\Core\Support\TenantTime;

/**
 * Read side of the indicator dashboard (design D1/D5, REQ-RPT-004/005).
 * Closed months are served from the materialized snapshot; the current
 * (open) month is computed live through the SAME IndicatorAggregationQuery
 * the nightly builder uses, wrapped in a tenant-scoped cache.
 */
class IndicatorReportService
{
    use BuildsTenantReports;
    use CachesTenantReports;

    private const CACHE_ENDPOINT = 'indicator_period_row';

    public function __construct(private readonly IndicatorAggregationQuery $aggregation) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(string $period, ?string $healthCenterId): array
    {
        $row = $this->rowForPeriod($period, $healthCenterId);

        return [
            'period' => $period,
            'health_center_id' => $healthCenterId,
            'screenings_applied' => $row['screenings_applied'],
            'screenings_completed' => $row['screenings_completed'],
            'children_screened' => $row['children_screened'],
            'result_green_count' => $row['result_green_count'],
            'result_yellow_count' => $row['result_yellow_count'],
            'result_red_count' => $row['result_red_count'],
            'percent_green' => $this->safePercent($row['result_green_count'], $row['screenings_applied']),
            'percent_yellow' => $this->safePercent($row['result_yellow_count'], $row['screenings_applied']),
            'percent_red' => $this->safePercent($row['result_red_count'], $row['screenings_applied']),
            'first_time_count' => $row['first_time_count'],
            'alert_sign_screening_count' => $row['alert_sign_screening_count'],
            'risk_factor_screening_count' => $row['risk_factor_screening_count'],
            'confirmatory_pending_count' => $row['confirmatory_pending_count'],
            'situation_breakdown' => $row['situation_breakdown'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monthlySeries(int $months, ?string $healthCenterId): array
    {
        $series = [];
        $current = Carbon::createFromFormat('!Y-m', TenantTime::currentMonth());

        for ($i = $months - 1; $i >= 0; $i--) {
            $period = $current->copy()->subMonths($i)->format('Y-m');
            $row = $this->rowForPeriod($period, $healthCenterId);

            $series[] = [
                'period' => $period,
                'screenings_applied' => $row['screenings_applied'],
                'result_green_count' => $row['result_green_count'],
                'result_yellow_count' => $row['result_yellow_count'],
                'result_red_count' => $row['result_red_count'],
            ];
        }

        return $series;
    }

    /**
     * @return array<string, mixed>
     */
    public function areaBreakdown(string $period, ?string $healthCenterId): array
    {
        $row = $this->rowForPeriod($period, $healthCenterId);

        return [
            'period' => $period,
            'health_center_id' => $healthCenterId,
            'areas' => $row['area_breakdown'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function concentrate(string $from, string $to, ?string $healthCenterId): array
    {
        $rows = [];
        $cursor = Carbon::createFromFormat('!Y-m', $from)->startOfMonth();
        $end = Carbon::createFromFormat('!Y-m', $to)->startOfMonth();

        while ($cursor->lte($end)) {
            $period = $cursor->format('Y-m');
            $row = $this->rowForPeriod($period, $healthCenterId);

            $rows[] = array_merge(['period' => $period, 'health_center_id' => $healthCenterId], $row);

            $cursor->addMonth();
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowForPeriod(string $period, ?string $healthCenterId): array
    {
        $periodStart = Carbon::createFromFormat('!Y-m', $period)->startOfMonth();
        $isClosed = $periodStart->lt(Carbon::createFromFormat('!Y-m', TenantTime::currentMonth()));

        if ($isClosed) {
            return $this->rowFromSnapshot($periodStart, $healthCenterId);
        }

        return $this->cachedReport(
            self::CACHE_ENDPOINT,
            ['period' => $period, 'health_center_id' => $healthCenterId ?? ''],
            fn () => $this->rowFromLiveAggregation($periodStart, $healthCenterId)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromSnapshot(Carbon $periodStart, ?string $healthCenterId): array
    {
        $snapshot = ScreeningIndicatorSnapshot::query()
            ->where('period_start', $periodStart->toDateString())
            ->where('health_center_id', $healthCenterId)
            ->first();

        if ($snapshot === null) {
            return $this->zeroRow();
        }

        return [
            'screenings_applied' => $snapshot->screenings_applied,
            'screenings_completed' => $snapshot->screenings_completed,
            'children_screened' => $snapshot->children_screened,
            'result_green_count' => $snapshot->result_green_count,
            'result_yellow_count' => $snapshot->result_yellow_count,
            'result_red_count' => $snapshot->result_red_count,
            'first_time_count' => $snapshot->first_time_count,
            'alert_sign_screening_count' => $snapshot->alert_sign_screening_count,
            'risk_factor_screening_count' => $snapshot->risk_factor_screening_count,
            'confirmatory_pending_count' => $snapshot->confirmatory_pending_count,
            'area_breakdown' => $snapshot->area_breakdown ?? [],
            'situation_breakdown' => $snapshot->situation_breakdown ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFromLiveAggregation(Carbon $periodStart, ?string $healthCenterId): array
    {
        $rows = $this->aggregation->forPeriod($periodStart, (clone $periodStart)->endOfMonth());

        foreach ($rows as $row) {
            if ($row['health_center_id'] === $healthCenterId) {
                unset($row['health_center_id']);

                return $row;
            }
        }

        return $this->zeroRow();
    }

    /**
     * @return array<string, mixed>
     */
    private function zeroRow(): array
    {
        return [
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
    }
}
