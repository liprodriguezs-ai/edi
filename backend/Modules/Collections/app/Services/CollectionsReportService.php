<?php

declare(strict_types=1);

namespace Modules\Collections\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeStatus;
use Modules\Housing\Models\Unit;
use QCH\Core\Services\BuildsTenantReports;
use QCH\Core\Services\CachesTenantReports;
use QCH\Core\Support\TenantMoney;

/**
 * Read-only aggregation service for the collections reporting endpoints:
 * the aging report (aging) and the delinquency dashboard/export
 * (delinquencyByUnit, consumed by PaymentController::delinquency[Report]).
 *
 * The outstanding formula lives in ONE place — Charge::outstandingSqlExpression()
 * (parity-documented against ChargeStatusService::effectiveExpected()):
 *   outstanding = max(0, max(0, expected_amount - discount_amount) + surcharge_amount - paid_amount)
 * Raw minor-unit integers throughout (getAttributes() bypasses the money cast).
 *
 * Every query enters through the Charge model (BelongsToTenant), so
 * TenantScope injects the tenant_id automatically.
 *
 * Response shape (frontend schema ICobranzaPayload — money fields serialize as
 * Money objects, mapped from the cached ints AFTER cachedReport() resolves):
 * {
 *   totals: { total_outstanding: Money, units_with_debt, avg_days_overdue, collection_rate_pct },
 *   buckets: { current: Money, d1_30: Money, d31_60: Money, d61_90: Money, d90_plus: Money },
 *   by_status: [{ status_key, label, count, amount: Money }],
 *   top_debtors: [{ unit_label, outstanding: Money, charge_count }]
 * }
 */
class CollectionsReportService
{
    use BuildsTenantReports;
    use CachesTenantReports;

    /**
     * UUID → stable key map for ChargeStatus catalog rows.
     * Used to produce a stable status_key string in the response without
     * depending on a "key" column that does not exist on ChargeStatus.
     *
     * @var array<string, string>
     */
    private const STATUS_KEY_MAP = [
        ChargeStatus::COMPLETED => 'completed',
        ChargeStatus::PARTIAL => 'partial',
        ChargeStatus::OVERDUE => 'overdue',
        ChargeStatus::DUE_SOON => 'due_soon',
        ChargeStatus::OVERPAID => 'overpaid',
        ChargeStatus::PENDING => 'pending',
        ChargeStatus::PAYMENT_PLAN => 'payment_plan',
    ];

    /**
     * Return the full aging payload aligned with the frontend ICobranzaPayload schema.
     *
     * @return array<string, mixed>
     */
    public function aging(string $timezone = 'America/Mexico_City'): array
    {
        /** @var array<string, mixed> */
        $report = $this->cachedReport(
            'aging',
            ['timezone' => $timezone],
            function () use ($timezone): array {
                return $this->computeAging($timezone);
            },
        );

        return [
            'totals' => [
                'total_outstanding' => TenantMoney::make($report['totals']['total_outstanding_minor']),
                'units_with_debt' => $report['totals']['units_with_debt'],
                'avg_days_overdue' => $report['totals']['avg_days_overdue'],
                'collection_rate_pct' => $report['totals']['collection_rate_pct'],
            ],
            'buckets' => [
                'current' => TenantMoney::make($report['buckets']['current_minor']),
                'd1_30' => TenantMoney::make($report['buckets']['d1_30_minor']),
                'd31_60' => TenantMoney::make($report['buckets']['d31_60_minor']),
                'd61_90' => TenantMoney::make($report['buckets']['d61_90_minor']),
                'd90_plus' => TenantMoney::make($report['buckets']['d90_plus_minor']),
            ],
            'by_status' => array_map(fn (array $row): array => [
                'status_key' => $row['status_key'],
                'label' => $row['label'],
                'count' => $row['count'],
                'amount' => TenantMoney::make($row['amount_minor']),
            ], $report['by_status']),
            'top_debtors' => array_map(fn (array $row): array => [
                'unit_label' => $row['unit_label'],
                'outstanding' => TenantMoney::make($row['outstanding_minor']),
                'charge_count' => $row['charge_count'],
            ], $report['top_debtors']),
        ];
    }

    /**
     * Delinquency aggregation: units with overdue/partial charges, grouped per
     * unit with their outstanding debt in MINOR UNITS. Single source of truth
     * for BOTH the JSON dashboard (GET /delinquency) and the xlsx export
     * (GET /delinquency/report) — the outstanding formula must never drift
     * between them.
     *
     * Aggregates in SQL (GROUP BY unit_id + HAVING): O(units with debt) rows
     * hydrate per request instead of every overdue charge of the tenant.
     *
     * Filters apply at two levels:
     * - CHARGE level: `charge_concept_ids` narrows which charges aggregate, so a
     *   concept-filtered row reports the outstanding/charge_count of THOSE
     *   concepts only ("cuánto deben de mantenimiento"). `search` matches the
     *   unit (number, type label, tower) OR the name of an ACTIVE resident
     *   (unit_user.end_date IS NULL) — one combined text box on the UI.
     * - ROW level (HAVING, on the aggregates): `min_months_overdue` keeps units
     *   whose OLDEST unpaid due date is at least N calendar months in the past;
     *   `min_amount` / `max_amount` bound the aggregated outstanding.
     *
     * @param array{
     *     search?: string|null,
     *     charge_concept_ids?: array<int, string>,
     *     min_months_overdue?: int|null,
     *     min_amount?: int|null,
     *     max_amount?: int|null
     * } $filters
     * @return Collection<int, array{
     *     unit_id: string|null,
     *     unit_number: string|null,
     *     unit_full_identifier: string|null,
     *     outstanding_minor_units: int,
     *     oldest_due_date: Carbon|null,
     *     charge_count: int
     * }>
     */
    public function delinquencyByUnit(array $filters = []): Collection
    {
        $search = $filters['search'] ?? null;
        $conceptIds = $filters['charge_concept_ids'] ?? [];
        $minMonths = $filters['min_months_overdue'] ?? null;
        $minAmount = $filters['min_amount'] ?? null;
        $maxAmount = $filters['max_amount'] ?? null;

        $outstandingSql = Charge::outstandingSqlExpression();

        $aggregates = Charge::query()
            ->whereIn('charge_status_id', [
                ChargeStatus::OVERDUE,
                ChargeStatus::PARTIAL,
            ])
            ->where('is_cancelled', false)
            ->when($conceptIds !== [], fn ($query) => $query->whereIn('charge_concept_id', $conceptIds))
            ->when($search !== null, function ($query) use ($search): void {
                // LOWER(...) LIKE instead of ILIKE so the match stays portable and
                // case-insensitive regardless of collation (project convention).
                // LIKE wildcards in the user input are escaped so `%`/`_` search
                // literally instead of matching everything.
                $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $query->whereHas('unit', function ($unitQuery) use ($term): void {
                    $unitQuery->where(function ($match) use ($term): void {
                        $match->whereRaw('LOWER(number) LIKE ?', [$term])
                            ->orWhereHas('unitType', fn ($type) => $type->whereRaw('LOWER(name) LIKE ?', [$term]))
                            ->orWhereHas('tower', fn ($tower) => $tower->whereRaw('LOWER(name) LIKE ?', [$term]))
                            ->orWhereHas('unitUsers', function ($link) use ($term): void {
                                $link->whereNull('end_date')
                                    ->whereHas('user', fn ($user) => $user->whereRaw('LOWER(name) LIKE ?', [$term]));
                            });
                    });
                });
            })
            ->selectRaw(
                'unit_id, '
                ."SUM({$outstandingSql}) AS outstanding_minor_units, "
                .'MIN(due_date) AS oldest_due_date, '
                .'COUNT(*) AS charge_count',
            )
            ->groupBy('unit_id')
            ->when($minAmount !== null, fn ($query) => $query->havingRaw("SUM({$outstandingSql}) >= ?", [$minAmount]))
            ->when($maxAmount !== null, fn ($query) => $query->havingRaw("SUM({$outstandingSql}) <= ?", [$maxAmount]))
            // "Meses de deuda": the oldest unpaid due date is at least N calendar
            // months old. Direct date comparison — never diffInDays (its sign bit
            // already burned this module once).
            ->when($minMonths !== null, fn ($query) => $query->havingRaw(
                'MIN(due_date) <= ?',
                [Carbon::today()->subMonthsNoOverflow($minMonths)->toDateString()],
            ))
            ->orderByRaw("SUM({$outstandingSql}) DESC")
            ->get();

        // ONE query resolves the unit labels; unitType + tower feed
        // Unit::full_identifier (avoids N+1 in the accessor). A unit missing from
        // the map (soft-deleted) yields the documented null unit fields.
        $units = Unit::query()
            ->with(['unitType', 'tower'])
            ->whereIn('id', $aggregates->pluck('unit_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        return $aggregates->map(function (Charge $aggregate) use ($units): array {
            $attributes = $aggregate->getAttributes();
            $unit = $units->get($attributes['unit_id']);

            return [
                'unit_id' => $unit?->id,
                'unit_number' => $unit?->number,
                // Human-readable identifier (e.g. "Casa 3" / "Torre Norte · Piso 1 · Depto 101")
                // so the frontend shows the unit con su tipo, no solo el número.
                'unit_full_identifier' => $unit?->full_identifier,
                'outstanding_minor_units' => (int) $attributes['outstanding_minor_units'],
                'oldest_due_date' => isset($attributes['oldest_due_date'])
                    ? Carbon::parse($attributes['oldest_due_date'])
                    : null,
                'charge_count' => (int) $attributes['charge_count'],
            ];
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function computeAging(string $timezone): array
    {
        // Load all overdue/partial non-cancelled charges with their unit and status.
        // Single query — no N+1: eager-loads unit + charge_status in 2 additional queries.
        // The per-charge outstanding is computed by the DB via the SHARED expression
        // (Charge::outstandingSqlExpression) — never re-derive the formula in PHP.
        $charges = Charge::query()
            ->whereIn('charge_status_id', [ChargeStatus::OVERDUE, ChargeStatus::PARTIAL])
            ->where('is_cancelled', false)
            ->select('charge.*')
            ->selectRaw(Charge::outstandingSqlExpression().' AS outstanding_minor_units')
            ->with(['unit', 'unit.unitType', 'unit.tower', 'charge_status'])
            ->get();

        $today = Carbon::now($timezone)->startOfDay();

        // ── Aging buckets ────────────────────────────────────────────────────
        $buckets = [
            'current_minor' => 0,
            'd1_30_minor' => 0,
            'd31_60_minor' => 0,
            'd61_90_minor' => 0,
            'd90_plus_minor' => 0,
        ];

        $totalOutstanding = 0;
        $unitIds = [];
        $totalDaysOverdue = 0;
        $overdueChargeCount = 0;

        // ── By-status grouping (keyed by charge_status_id internally) ────────
        /** @var array<string, array{status_key: string, label: string, count: int, amount_minor: int}> $byStatus */
        $byStatus = [];

        // ── Top debtors (grouped by unit_id) ────────────────────────────────
        /** @var array<string, array{unit_id: string, unit_label: string, outstanding_minor: int, charge_count: int, oldest_due_date: string}> $debtors */
        $debtors = [];

        foreach ($charges as $charge) {
            $attrs = $charge->getAttributes();
            // DB-computed via Charge::outstandingSqlExpression() (parity with
            // ChargeStatusService::effectiveExpected() is documented THERE).
            $outstanding = (int) ($attrs['outstanding_minor_units'] ?? 0);

            $totalOutstanding += $outstanding;
            $unitId = $charge->unit_id;

            if ($unitId) {
                $unitIds[$unitId] = true;
            }

            // Bucket assignment.
            $dueDate = $charge->due_date
                ? Carbon::parse($charge->due_date)->startOfDay()
                : $today->copy();

            // diffInDays() is SIGNED: $dueDate->diffInDays($today) === $today − $dueDate
            // in whole days, so it is POSITIVE when the charge is past due and NEGATIVE
            // when it is still due in the future — exactly the sign we need. Do NOT
            // reconstruct the sign manually (that double-negates future dates and reports
            // them as overdue). Truncate toward zero so a same-day charge reads as 0.
            $daysPastDue = (int) $dueDate->diffInDays($today);

            // Accumulate days overdue for avg calculation. Only charges that are
            // ACTUALLY past due (daysPastDue > 0) contribute to both the numerator
            // and the denominator — otherwise a not-yet-due PARTIAL charge would
            // dilute the average toward 0.
            if ($daysPastDue > 0) {
                $totalDaysOverdue += $daysPastDue;
                $overdueChargeCount++;
            }

            if ($daysPastDue <= 0) {
                $buckets['current_minor'] += $outstanding;
            } elseif ($daysPastDue <= 30) {
                $buckets['d1_30_minor'] += $outstanding;
            } elseif ($daysPastDue <= 60) {
                $buckets['d31_60_minor'] += $outstanding;
            } elseif ($daysPastDue <= 90) {
                $buckets['d61_90_minor'] += $outstanding;
            } else {
                $buckets['d90_plus_minor'] += $outstanding;
            }

            // By-status grouping.
            $statusId = $charge->charge_status_id;
            if ($statusId) {
                if (! isset($byStatus[$statusId])) {
                    $byStatus[$statusId] = [
                        'status_key' => self::STATUS_KEY_MAP[$statusId] ?? 'unknown',
                        'label' => $charge->charge_status?->name ?? 'Desconocido',
                        'count' => 0,
                        'amount_minor' => 0,
                    ];
                }
                $byStatus[$statusId]['count']++;
                $byStatus[$statusId]['amount_minor'] += $outstanding;
            }

            // Top debtors accumulation.
            if ($unitId) {
                if (! isset($debtors[$unitId])) {
                    $unit = $charge->unit;
                    $debtors[$unitId] = [
                        'unit_id' => $unitId,
                        'unit_label' => $unit?->full_identifier ?? $unitId,
                        'outstanding_minor' => 0,
                        'charge_count' => 0,
                        'oldest_due_date' => $charge->due_date?->format('Y-m-d') ?? '',
                    ];
                }

                $debtors[$unitId]['outstanding_minor'] += $outstanding;
                $debtors[$unitId]['charge_count']++;

                // Keep the oldest (earliest) due date.
                $currentOldest = $debtors[$unitId]['oldest_due_date'];
                $thisDate = $charge->due_date?->format('Y-m-d') ?? '';
                if ($thisDate && ($currentOldest === '' || $thisDate < $currentOldest)) {
                    $debtors[$unitId]['oldest_due_date'] = $thisDate;
                }
            }
        }

        // ── Average days overdue ─────────────────────────────────────────────
        $avgDaysOverdue = $overdueChargeCount > 0
            ? (int) round($totalDaysOverdue / $overdueChargeCount)
            : 0;

        // ── Collection rate (charges due this calendar month) ────────────────
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();

        $issuedTotal = Charge::query()
            ->whereBetween('due_date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
            ->where('is_cancelled', false)
            ->count();

        $paidCount = Charge::query()
            ->whereBetween('due_date', [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')])
            ->where('is_cancelled', false)
            ->whereIn('charge_status_id', [ChargeStatus::COMPLETED, ChargeStatus::OVERPAID])
            ->count();

        $collectionRatePct = $this->safePercent($paidCount, $issuedTotal);

        // ── Top debtors — sort desc, take 10 ────────────────────────────────
        $topDebtors = collect($debtors)
            ->sortByDesc('outstanding_minor')
            ->take(10)
            ->map(fn ($d) => [
                'unit_label' => $d['unit_label'],
                'outstanding_minor' => $d['outstanding_minor'],
                'charge_count' => $d['charge_count'],
            ])
            ->values()
            ->all();

        return [
            'totals' => [
                'total_outstanding_minor' => $totalOutstanding,
                'units_with_debt' => count($unitIds),
                'avg_days_overdue' => $avgDaysOverdue,
                'collection_rate_pct' => $collectionRatePct,
            ],
            'buckets' => $buckets,
            'by_status' => array_values($byStatus),
            'top_debtors' => $topDebtors,
        ];
    }
}
