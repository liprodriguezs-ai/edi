<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collections\Models\Payment;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Services\TreasuryReportService;
use QCH\Core\Http\Controllers\Controller;

/**
 * Read-only treasury report endpoints.
 *
 * Authorization strategy: same as PaymentController::delinquency — authorize()
 * against a BelongsToTenant model class so the ability resolver has a concrete
 * class to inspect. Returns JSON payloads with monetary fields serialized as
 * Money objects ({amount, currency, formatted}) via the service's mapping.
 */
class TreasuryReportController extends Controller
{
    public function __construct(private readonly TreasuryReportService $service) {}

    /**
     * GET /treasury/income-expense-series?months=12
     * Permission: read.report_pnl
     *
     * Returns rolling P&L series (income, expenses, net per month) + totals.
     */
    public function incomeExpenseSeries(Request $request): JsonResponse
    {
        $this->authorize('read.report_pnl', Payment::class);

        $validated = $request->validate([
            'months' => ['sometimes', 'integer', 'min:1', 'max:24'],
        ]);
        $months = isset($validated['months']) ? (int) $validated['months'] : 12;

        return response()->json($this->service->incomeExpenseSeries($months));
    }

    /**
     * GET /treasury/cashflow-series?months=12
     * Permission: read.report_treasury
     *
     * Returns active bank account balances + cashflow series (inflow, outflow,
     * running cumulative balance per month).
     */
    public function cashflowSeries(Request $request): JsonResponse
    {
        $this->authorize('read.report_treasury', BankAccount::class);

        $validated = $request->validate([
            'months' => ['sometimes', 'integer', 'min:1', 'max:24'],
        ]);
        $months = isset($validated['months']) ? (int) $validated['months'] : 12;

        return response()->json($this->service->cashflowSeries($months));
    }

    /**
     * GET /treasury/expenses-breakdown?months=12
     * Permission: read.report_expenses
     *
     * Returns expense breakdown by category, by provider, monthly series,
     * and recurring vs one-off totals.
     */
    public function expensesBreakdown(Request $request): JsonResponse
    {
        $this->authorize('read.report_expenses', Expense::class);

        $validated = $request->validate([
            'months' => ['sometimes', 'integer', 'min:1', 'max:24'],
        ]);
        $months = isset($validated['months']) ? (int) $validated['months'] : 12;

        return response()->json($this->service->expensesBreakdown($months));
    }
}
