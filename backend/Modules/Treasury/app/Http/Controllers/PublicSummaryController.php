<?php

namespace Modules\Treasury\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentStatus;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseStatus;
use QCH\Core\Support\TenantMoney;

/**
 * Public financial summary endpoint (§9.5).
 *
 * Returns ONLY aggregate totals — no individual expense or payment rows and
 * (§9.2 / §9.6 r7) NO bank account names or balances for residents: per-account
 * detail is included only for callers holding read.bank_account.
 *
 * Period semantics: both income and expenses are bucketed by paid_at (payment
 * date). expense.year/month/fortnight are metadata of the PAID period (e.g.
 * fortnightly salaries), not the aggregation axis — same rule as the monthly
 * summary cron, so both views always agree.
 *
 * Query params:
 *   - year  (int, optional, defaults to current year)
 *   - month (int, optional, defaults to current month)
 */
class PublicSummaryController extends BaseController
{
    public function summary(Request $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);

        // Month boundaries — sargable against the (tenant_id, paid_at) index,
        // unlike whereYear/whereMonth which compile to date-extraction
        // expressions a plain b-tree on paid_at cannot satisfy.
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = (clone $start)->endOfMonth();

        // Total confirmed income for the period (payments with status=confirmed).
        $confirmedStatusId = PaymentStatus::CONFIRMED;

        $totalIncome = 0;
        if ($confirmedStatusId) {
            $totalIncome = (int) Payment::query()
                ->where('payment_status_id', $confirmedStatusId)
                ->whereBetween('paid_at', [$start, $end])
                ->sum(DB::raw('CAST(total_amount AS BIGINT)'));
        }

        // Total expenses for the period (non-voided expenses, bucketed by paid_at).
        $voidedStatusId = ExpenseStatus::VOIDED;

        $expenseQuery = Expense::query()
            ->whereBetween('paid_at', [$start, $end]);

        if ($voidedStatusId) {
            $expenseQuery->where('expense_status_id', '!=', $voidedStatusId);
        }

        $totalExpenses = (int) $expenseQuery->sum(DB::raw('CAST(amount AS BIGINT)'));

        // Expenses by category (aggregated — no individual rows).
        $expensesByCategory = Expense::query()
            ->selectRaw('expense_category_id, SUM(CAST(amount AS BIGINT)) as total')
            ->whereBetween('paid_at', [$start, $end])
            ->when($voidedStatusId, fn ($q) => $q->where('expense_status_id', '!=', $voidedStatusId))
            ->groupBy('expense_category_id')
            ->with('category:id,name')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category?->name ?? 'Sin categoría',
                'total' => (int) $row->total,
            ]);

        $netBalance = $totalIncome - $totalExpenses;

        $data = [
            'year' => $year,
            'month' => $month,
            'total_income' => TenantMoney::make($totalIncome),
            'total_expenses' => TenantMoney::make($totalExpenses),
            'net_balance' => TenantMoney::make($netBalance),
            'expenses_by_category' => $expensesByCategory->map(fn (array $row): array => [
                'category' => $row['category'],
                'total' => TenantMoney::make($row['total']),
            ]),
        ];

        // Per-account names/balances are NOT public (§9.2: Ver cuentas bancarias —
        // Resi ❌). Only callers with read.bank_account get the breakdown.
        $user = $request->user();
        if ($user !== null && ($user->tokenCan('read.bank_account') || $user->tokenCan('*'))) {
            $data['bank_account_balances'] = BankAccount::query()
                ->where('is_active', true)
                ->get(['name', 'current_balance'])
                ->map(fn ($account) => [
                    'name' => $account->name,
                    'current_balance' => TenantMoney::make((int) $account->getRawOriginal('current_balance')),
                ]);
        }

        return response()->json(['data' => $data]);
    }
}
