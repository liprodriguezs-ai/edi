<?php

namespace Modules\Reports\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\Reports\Http\Requests\IndicatorRequest;
use Modules\Reports\Services\IndicatorReportService;
use QCH\Core\Support\TenantTime;

/**
 * Aggregate, PII-free indicator dashboard (design §4, REQ-RPT-004/005).
 * Gated ONLY by `read.screening_indicator` -- never a Screening/ChildRecord
 * permission (REQ-RPT-004). Default periods are the tenant's civil month (TenantTime).
 */
class IndicatorController
{
    public function __construct(private readonly IndicatorReportService $service) {}

    public function summary(IndicatorRequest $request): JsonResponse
    {
        $period = $request->validated('period') ?? TenantTime::currentMonth();
        $healthCenterId = $request->validated('health_center_id');

        return response()->json(['data' => $this->service->summary($period, $healthCenterId)]);
    }

    public function monthlySeries(IndicatorRequest $request): JsonResponse
    {
        $months = (int) ($request->validated('months') ?? 12);
        $healthCenterId = $request->validated('health_center_id');

        return response()->json(['data' => $this->service->monthlySeries($months, $healthCenterId)]);
    }

    public function areaBreakdown(IndicatorRequest $request): JsonResponse
    {
        $period = $request->validated('period') ?? TenantTime::currentMonth();
        $healthCenterId = $request->validated('health_center_id');

        return response()->json(['data' => $this->service->areaBreakdown($period, $healthCenterId)]);
    }

    public function concentrate(IndicatorRequest $request): JsonResponse
    {
        $currentMonth = TenantTime::currentMonth();
        $to = $request->validated('to') ?? $currentMonth;
        $from = $request->validated('from')
            ?? CarbonImmutable::createFromFormat('!Y-m', $currentMonth)->subMonthsNoOverflow(11)->format('Y-m');
        $healthCenterId = $request->validated('health_center_id');

        return response()->json(['data' => $this->service->concentrate($from, $to, $healthCenterId)]);
    }
}
