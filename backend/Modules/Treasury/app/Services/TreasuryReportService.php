<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentStatus;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseStatus;
use QCH\Core\Services\BuildsTenantReports;
use QCH\Core\Services\CachesTenantReports;
use QCH\Core\Support\TenantMoney;

/**
 * Read-only aggregation service for the 3 treasury report endpoints.
 *
 * Internally (compute*() + cachedReport()) all money values are minor-unit
 * integers (centavos) — the cache payload and its keys are unchanged. Each
 * public method maps those ints to Money objects ({amount, currency, formatted})
 * AFTER reading the cache, using the tenant's active currency (TenantMoney::make).
 * No DB:: as a query SOURCE — every query enters via a BelongsToTenant model so
 * TenantScope injects the tenant_id automatically. DB::raw() expressions INSIDE
 * sum()/selectRaw() on those scoped builders are permitted (same pattern as
 * PublicSummaryController).
 *
 * Results are tenant-scoped cached via the CachesTenantReports trait.
 * TTL and toggle are read from config('dashboard.reports.*').
 */
class TreasuryReportService
{
    use BuildsTenantReports;
    use CachesTenantReports;
    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build an ordered list of month buckets (oldest-first) for a rolling
     * N-month window ending this month in the given timezone.
     *
     * Each bucket: ['year', 'month', 'label', 'key' (YYYY-MM), 'start', 'end']
     * where start/end are LOCAL calendar boundaries (first/last day of the
     * month, NOT tz-shifted to UTC). paid_at is a pure DATE column, so attribution
     * is by calendar year-month — converting boundaries to UTC would misfile a
     * day-1 row into the previous month for negative-offset tenant timezones.
     *
     * @param  int  $months  Number of months to cover (1–24).
     * @param  string  $timezone  IANA timezone string, e.g. "America/Mexico_City".
     * @return array<int, array{year: int, month: int, label: string, key: string, start: Carbon, end: Carbon}>
     */
    private function buildBuckets(int $months, string $timezone): array
    {
        /** @var array<string, string> Spanish month abbreviations (1-indexed). */
        $spanishMonths = [
            1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr',
            5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
        ];

        $buckets = [];
        $now = Carbon::now($timezone)->startOfMonth();

        for ($i = $months - 1; $i >= 0; $i--) {
            $anchor = $now->copy()->subMonths($i);
            $year = (int) $anchor->year;
            $month = (int) $anchor->month;

            $startLocal = $anchor->copy()->startOfMonth();
            $endLocal = $anchor->copy()->endOfMonth();

            $buckets[] = [
                'year' => $year,
                'month' => $month,
                'label' => ($spanishMonths[$month] ?? '').' '.$year,
                'key' => $anchor->format('Y-m'),
                'start' => $startLocal->copy(),
                'end' => $endLocal->copy(),
            ];
        }

        return $buckets;
    }

    /**
     * Monthly confirmed payment inflow, keyed by "YYYY-MM".
     *
     * Single query over the full window, bucketed in PHP — avoids N queries
     * (was one query per bucket). Compatible with SQLite and Postgres: uses
     * CAST(... AS INTEGER) (valid in both) and whereBetween for UTC boundaries.
     *
     * @param  array<int, array{key: string, start: Carbon, end: Carbon}>  $buckets
     * @return array<string, int>
     */
    private function monthlyInflow(array $buckets): array
    {
        if (empty($buckets)) {
            return [];
        }

        $windowStart = $buckets[0]['start'];
        $windowEnd = $buckets[array_key_last($buckets)]['end'];

        // One query: fetch all confirmed payments in the window with paid_at + amount.
        $rows = Payment::query()
            ->where('payment_status_id', PaymentStatus::CONFIRMED)
            ->whereBetween('paid_at', [$windowStart, $windowEnd])
            ->get(['paid_at', 'total_amount']);

        $result = array_fill_keys(array_column($buckets, 'key'), 0);

        foreach ($rows as $row) {
            // paid_at is a pure DATE column → attribute by its calendar year-month,
            // never by a tz-shifted instant (which would misfile a day-1 row into
            // the prior month for negative-offset tenant timezones).
            $key = Carbon::parse($row->getAttributes()['paid_at'])->format('Y-m');
            if (array_key_exists($key, $result)) {
                $result[$key] += (int) $row->getAttributes()['total_amount'];
            }
        }

        return $result;
    }

    /**
     * Monthly non-voided expense outflow, keyed by "YYYY-MM".
     *
     * Single query over the full window, bucketed in PHP — avoids N queries
     * (was one query per bucket). Compatible with SQLite and Postgres.
     *
     * @param  array<int, array{key: string, start: Carbon, end: Carbon}>  $buckets
     * @return array<string, int>
     */
    private function monthlyOutflow(array $buckets): array
    {
        if (empty($buckets)) {
            return [];
        }

        $windowStart = $buckets[0]['start'];
        $windowEnd = $buckets[array_key_last($buckets)]['end'];

        // One query: fetch all non-voided expenses in the window with paid_at + amount.
        $rows = Expense::query()
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->whereBetween('paid_at', [$windowStart, $windowEnd])
            ->get(['paid_at', 'amount']);

        $result = array_fill_keys(array_column($buckets, 'key'), 0);

        foreach ($rows as $row) {
            // paid_at is a pure DATE column → attribute by its calendar year-month,
            // never by a tz-shifted instant (which would misfile a day-1 row into
            // the prior month for negative-offset tenant timezones).
            $key = Carbon::parse($row->getAttributes()['paid_at'])->format('Y-m');
            if (array_key_exists($key, $result)) {
                $result[$key] += (int) $row->getAttributes()['amount'];
            }
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * P&L income-expense series for the rolling N-month window.
     *
     * Response shape (spec §5.1 — money fields serialize as Money objects,
     * mapped from the cached ints AFTER cachedReport() resolves):
     * {
     *   series: [{ year, month, label, income: Money, expense: Money, net: Money }],
     *   totals: { income: Money, expense: Money, net: Money, margin_pct }
     * }
     *
     * @return array{series: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function incomeExpenseSeries(int $months, string $timezone = 'America/Mexico_City'): array
    {
        /** @var array{series: list<array<string, int|string>>, totals: array<string, int>} */
        $report = $this->cachedReport(
            'income_expense_series',
            ['months' => $months, 'timezone' => $timezone],
            function () use ($months, $timezone): array {
                return $this->computeIncomeExpenseSeries($months, $timezone);
            },
        );

        return [
            'series' => array_map(fn (array $bucket): array => [
                'year' => $bucket['year'],
                'month' => $bucket['month'],
                'label' => $bucket['label'],
                'income' => TenantMoney::make($bucket['income_minor']),
                'expense' => TenantMoney::make($bucket['expense_minor']),
                'net' => TenantMoney::make($bucket['net_minor']),
            ], $report['series']),
            'totals' => [
                'income' => TenantMoney::make($report['totals']['income_minor']),
                'expense' => TenantMoney::make($report['totals']['expense_minor']),
                'net' => TenantMoney::make($report['totals']['net_minor']),
                'margin_pct' => $report['totals']['margin_pct'],
            ],
        ];
    }

    /**
     * @return array{series: list<array<string, int|string>>, totals: array<string, int>}
     */
    private function computeIncomeExpenseSeries(int $months, string $timezone): array
    {
        $buckets = $this->buildBuckets($months, $timezone);
        $inflow = $this->monthlyInflow($buckets);
        $outflow = $this->monthlyOutflow($buckets);

        $series = [];
        $totalIncome = 0;
        $totalExpense = 0;

        foreach ($buckets as $bucket) {
            $income = $inflow[$bucket['key']] ?? 0;
            $expense = $outflow[$bucket['key']] ?? 0;
            $net = $income - $expense;

            $totalIncome += $income;
            $totalExpense += $expense;

            $series[] = [
                'year' => $bucket['year'],
                'month' => $bucket['month'],
                'label' => $bucket['label'],
                'income_minor' => $income,
                'expense_minor' => $expense,
                'net_minor' => $net,
            ];
        }

        $marginPct = $this->safePercent($totalIncome - $totalExpense, $totalIncome);

        return [
            'series' => $series,
            'totals' => [
                'income_minor' => $totalIncome,
                'expense_minor' => $totalExpense,
                'net_minor' => $totalIncome - $totalExpense,
                'margin_pct' => $marginPct,
            ],
        ];
    }

    /**
     * Cashflow series with account balances for the rolling N-month window.
     *
     * Response shape (frontend schema ITesoreriaPayload — money fields serialize
     * as Money objects, mapped from the cached ints AFTER cachedReport() resolves):
     * {
     *   accounts: [{ id, name, current_balance: Money }],
     *   series: [{ label, year, month, inflow: Money, outflow: Money, running_balance: Money }],
     *   totals: { total_balance: Money, account_count, current_month_inflow: Money, current_month_outflow: Money }
     * }
     *
     * running_balance is an ABSOLUTE balance line, not a cumulative net. It is
     * back-computed from the real summed account balance (in minor units, BEFORE
     * wrapping): the final point equals total_balance, and each earlier point is
     * that total minus the net movement that happened after it. The series is
     * seeded with the opening balance = total_balance_minor − Σ(net over the window).
     *
     * @return array<string, mixed>
     */
    public function cashflowSeries(int $months, string $timezone = 'America/Mexico_City'): array
    {
        /** @var array<string, mixed> */
        $report = $this->cachedReport(
            'cashflow_series',
            ['months' => $months, 'timezone' => $timezone],
            function () use ($months, $timezone): array {
                return $this->computeCashflowSeries($months, $timezone);
            },
        );

        return [
            'accounts' => array_map(fn (array $account): array => [
                'id' => $account['id'],
                'name' => $account['name'],
                'current_balance' => TenantMoney::make($account['current_balance_minor']),
            ], $report['accounts']),
            'series' => array_map(fn (array $bucket): array => [
                'label' => $bucket['label'],
                'year' => $bucket['year'],
                'month' => $bucket['month'],
                'inflow' => TenantMoney::make($bucket['inflow_minor']),
                'outflow' => TenantMoney::make($bucket['outflow_minor']),
                'running_balance' => TenantMoney::make($bucket['running_balance_minor']),
            ], $report['series']),
            'totals' => [
                'total_balance' => TenantMoney::make($report['totals']['total_balance_minor']),
                'account_count' => $report['totals']['account_count'],
                'current_month_inflow' => TenantMoney::make($report['totals']['current_month_inflow_minor']),
                'current_month_outflow' => TenantMoney::make($report['totals']['current_month_outflow_minor']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function computeCashflowSeries(int $months, string $timezone): array
    {
        $buckets = $this->buildBuckets($months, $timezone);
        $inflow = $this->monthlyInflow($buckets);
        $outflow = $this->monthlyOutflow($buckets);

        $accounts = BankAccount::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'current_balance']);

        $accountData = $accounts->map(fn ($acc) => [
            'id' => $acc->id,
            'name' => $acc->name,
            'current_balance_minor' => (int) $acc->getRawOriginal('current_balance'),
        ])->values()->all();

        $totalBalance = (int) $accounts->sum(fn ($acc) => (int) $acc->getRawOriginal('current_balance'));

        // Identify the current-month bucket key using the tenant timezone.
        $now = Carbon::now($timezone);
        $currentMonthKey = $now->format('Y-m');

        // Seed the running balance with the opening balance so the line is an
        // absolute balance whose final point equals total_balance_minor:
        //   opening = total_balance_minor − Σ(net over the window)
        $windowNet = 0;
        foreach ($buckets as $bucket) {
            $windowNet += (($inflow[$bucket['key']] ?? 0) - ($outflow[$bucket['key']] ?? 0));
        }

        $series = [];
        $runningBalance = $totalBalance - $windowNet;

        foreach ($buckets as $bucket) {
            $monthInflow = $inflow[$bucket['key']] ?? 0;
            $monthOutflow = $outflow[$bucket['key']] ?? 0;
            $runningBalance += ($monthInflow - $monthOutflow);

            $series[] = [
                'label' => $bucket['label'],
                'year' => $bucket['year'],
                'month' => $bucket['month'],
                'inflow_minor' => $monthInflow,
                'outflow_minor' => $monthOutflow,
                'running_balance_minor' => $runningBalance,
            ];
        }

        $currentMonthInflow = $inflow[$currentMonthKey] ?? 0;
        $currentMonthOutflow = $outflow[$currentMonthKey] ?? 0;

        return [
            'accounts' => $accountData,
            'series' => $series,
            'totals' => [
                'total_balance_minor' => $totalBalance,
                'account_count' => $accounts->count(),
                'current_month_inflow_minor' => $currentMonthInflow,
                'current_month_outflow_minor' => $currentMonthOutflow,
            ],
        ];
    }

    /**
     * Expenses breakdown for the rolling N-month window.
     *
     * Response shape (frontend schema IGastosPayload — money fields serialize
     * as Money objects, mapped from the cached ints AFTER cachedReport() resolves):
     * {
     *   totals: { total: Money, recurring: Money, one_off: Money, recurring_pct, provider_count },
     *   by_category: [{ expense_category_id, label, amount: Money, pct }],
     *   by_provider: [{ provider_id, label, amount: Money }],
     *   monthly: [{ label, year, month, amount: Money }]
     * }
     *
     * @return array<string, mixed>
     */
    public function expensesBreakdown(int $months, string $timezone = 'America/Mexico_City'): array
    {
        /** @var array<string, mixed> */
        $report = $this->cachedReport(
            'expenses_breakdown',
            ['months' => $months, 'timezone' => $timezone],
            function () use ($months, $timezone): array {
                return $this->computeExpensesBreakdown($months, $timezone);
            },
        );

        return [
            'totals' => [
                'total' => TenantMoney::make($report['totals']['total_minor']),
                'recurring' => TenantMoney::make($report['totals']['recurring_minor']),
                'one_off' => TenantMoney::make($report['totals']['one_off_minor']),
                'recurring_pct' => $report['totals']['recurring_pct'],
                'provider_count' => $report['totals']['provider_count'],
            ],
            'by_category' => array_map(fn (array $row): array => [
                'expense_category_id' => $row['expense_category_id'],
                'label' => $row['label'],
                'amount' => TenantMoney::make($row['amount_minor']),
                'pct' => $row['pct'],
            ], $report['by_category']),
            'by_provider' => array_map(fn (array $row): array => [
                'provider_id' => $row['provider_id'],
                'label' => $row['label'],
                'amount' => TenantMoney::make($row['amount_minor']),
            ], $report['by_provider']),
            'monthly' => array_map(fn (array $row): array => [
                'label' => $row['label'],
                'year' => $row['year'],
                'month' => $row['month'],
                'amount' => TenantMoney::make($row['amount_minor']),
            ], $report['monthly']),
        ];
    }

    /** @return array<string, mixed> */
    private function computeExpensesBreakdown(int $months, string $timezone): array
    {
        $buckets = $this->buildBuckets($months, $timezone);

        $windowStart = $buckets[0]['start'];
        $windowEnd = $buckets[array_key_last($buckets)]['end'];

        // Total non-voided expenses in window.
        $totalMinor = (int) Expense::query()
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->whereBetween('paid_at', [$windowStart, $windowEnd])
            ->sum(DB::raw('CAST(amount AS INTEGER)'));

        // By category — selectRaw + groupBy (same pattern as PublicSummaryController).
        $byCategory = Expense::query()
            ->selectRaw('expense_category_id, SUM(CAST(amount AS INTEGER)) as cat_total')
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->whereBetween('paid_at', [$windowStart, $windowEnd])
            ->groupBy('expense_category_id')
            ->with('category:id,name')
            ->get()
            ->map(function ($row) use ($totalMinor): array {
                $amount = (int) $row->cat_total;
                $pct = $this->safePercent($amount, $totalMinor);

                return [
                    'expense_category_id' => $row->expense_category_id,
                    'label' => $row->category?->name ?? 'Sin categoría',
                    'amount_minor' => $amount,
                    'pct' => $pct,
                ];
            })
            ->sortByDesc('amount_minor')
            ->values()
            ->all();

        // By provider — non-voided expenses with a provider_id.
        $byProvider = Expense::query()
            ->selectRaw('provider_id, SUM(CAST(amount AS INTEGER)) as prov_total')
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->whereNotNull('provider_id')
            ->whereBetween('paid_at', [$windowStart, $windowEnd])
            ->groupBy('provider_id')
            ->with('provider:id,name')
            ->get()
            ->map(fn ($row) => [
                'provider_id' => $row->provider_id,
                'label' => $row->provider?->name ?? 'Proveedor desconocido',
                'amount_minor' => (int) $row->prov_total,
            ])
            ->sortByDesc('amount_minor')
            ->values()
            ->all();

        // Monthly series using pre-computed outflow buckets.
        $outflow = $this->monthlyOutflow($buckets);
        $monthly = array_map(fn ($bucket) => [
            'label' => $bucket['label'],
            'year' => $bucket['year'],
            'month' => $bucket['month'],
            'amount_minor' => $outflow[$bucket['key']] ?? 0,
        ], $buckets);

        // Recurring: expenses linked to is_recurring=true providers.
        $recurringMinor = (int) Expense::query()
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->whereBetween('paid_at', [$windowStart, $windowEnd])
            ->whereHas('provider', fn ($q) => $q->where('is_recurring', true))
            ->sum(DB::raw('CAST(amount AS INTEGER)'));

        $oneOffMinor = $totalMinor - $recurringMinor;
        $recurringPct = $this->safePercent($recurringMinor, $totalMinor);
        $providerCount = count($byProvider);

        return [
            'totals' => [
                'total_minor' => $totalMinor,
                'recurring_minor' => $recurringMinor,
                'one_off_minor' => $oneOffMinor,
                'recurring_pct' => $recurringPct,
                'provider_count' => $providerCount,
            ],
            'by_category' => $byCategory,
            'by_provider' => $byProvider,
            'monthly' => $monthly,
        ];
    }
}
