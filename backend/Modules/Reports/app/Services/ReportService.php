<?php

namespace Modules\Reports\Services;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Access\Models\AccessLog;
use Modules\Announcements\Models\Announcement;
use Modules\Announcements\Models\AnnouncementStatus;
use Modules\Board\Models\BoardMember;
use Modules\Board\Models\BoardPeriod;
use Modules\Board\Models\BoardPeriodStatus;
use Modules\Collaborator\Models\Collaborator;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\CreditBalance;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentStatus;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitUser;
use Modules\Projects\Models\CommunityProject;
use Modules\Providers\Models\Provider;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseStatus;

/**
 * ReportService — READ-ONLY aggregations over existing tenant data.
 *
 * All methods operate inside a tenant context (tenancy must be initialized).
 * No new domain tables are written; queries ONLY read existing tables.
 */
class ReportService
{
    /**
     * Delinquency report: units with at least one overdue charge.
     *
     * @return array{units: Collection, total_overdue_amount: int, unit_count: int}
     */
    public function delinquency(): array
    {
        $overdueStatus = ChargeStatus::find(ChargeStatus::OVERDUE);

        if (! $overdueStatus) {
            return ['units' => collect(), 'total_overdue_amount' => 0, 'unit_count' => 0];
        }

        $charges = Charge::query()
            ->where('charge_status_id', $overdueStatus->id)
            ->where('is_cancelled', false)
            ->with(['unit.unitType', 'unit.tower', 'concept'])
            ->get();

        $unitGroups = $charges->groupBy('unit_id');

        $units = $unitGroups->map(function (Collection $unitCharges): array {
            $unit = $unitCharges->first()->unit;
            // Mirrors Charge::outstandingSqlExpression(): discounts reduce the expected
            // amount and accrued surcharges add to it — expected−paid alone misreports
            // units with discounts or late fees.
            $totalDue = $unitCharges->sum(function (Charge $c): int {
                $expected = (int) $c->getRawOriginal('expected_amount');
                $discount = (int) $c->getRawOriginal('discount_amount');
                $surcharge = (int) $c->getRawOriginal('surcharge_amount');
                $paid = (int) $c->getRawOriginal('paid_amount');

                return max(0, max(0, $expected - $discount) + $surcharge - $paid);
            });

            return [
                'unit_id' => $unit?->id,
                'unit_number' => $unit?->number,
                'unit_full_identifier' => $unit?->full_identifier ?? $unit?->number,
                'overdue_charges' => $unitCharges->count(),
                'total_overdue_amount' => $totalDue,
                'oldest_due_date' => $unitCharges->min('due_date'),
            ];
        })->values();

        $totalOverdue = $units->sum('total_overdue_amount');

        return [
            'units' => $units,
            'total_overdue_amount' => $totalOverdue,
            'unit_count' => $units->count(),
        ];
    }

    /**
     * Income statement for a period: confirmed payments minus expenses.
     *
     * @return array{period_start: string, period_end: string, total_income: int, total_expenses: int, net: int, payments: Collection, expenses: Collection}
     */
    public function incomeStatement(string $periodStart, string $periodEnd): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();

        $confirmedStatus = PaymentStatus::find(PaymentStatus::CONFIRMED);

        $payments = Payment::query()
            ->when($confirmedStatus, fn ($q) => $q->where('payment_status_id', $confirmedStatus->id))
            ->whereBetween('paid_at', [$start, $end])
            ->get();

        // reports-07 — voided expenses are reversed money and must not distort
        // the income statement (keeps F01 consistent with F02/F04).
        $expenses = Expense::query()
            ->whereBetween('paid_at', [$start, $end])
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->get();

        $totalIncome = $payments->sum(fn (Payment $p): int => (int) $p->getRawOriginal('total_amount'));
        $totalExpenses = $expenses->sum(fn (Expense $e): int => (int) $e->getRawOriginal('amount'));

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net' => $totalIncome - $totalExpenses,
            'payments' => $payments,
            'expenses' => $expenses,
        ];
    }

    /**
     * Account balances: current balance per bank account.
     *
     * @return Collection<int, BankAccount>
     */
    public function accountBalances(): Collection
    {
        return BankAccount::query()
            ->where('is_active', true)
            ->with(['type', 'purpose'])
            ->orderByDesc('is_primary')
            ->get();
    }

    /**
     * Audit log: read-only from the audits table, scoped to current tenant.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator
     */
    public function auditLog(array $filters = [], int $perPage = 50): mixed
    {
        $query = DB::table('audits')
            ->where('tenant_id', tenant('id'))
            ->orderByDesc('created_at');

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (! empty($filters['auditable_type'])) {
            $query->where('auditable_type', $filters['auditable_type']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->paginate($perPage);
    }

    /**
     * Collection progress for a specific charge batch.
     *
     * @return array{batch_id: string, total_charges: int, paid_count: int, overdue_count: int, pending_count: int, total_expected: int, total_collected: int, progress_pct: float}
     */
    public function collectionProgress(string $batchId): array
    {
        $charges = Charge::query()
            ->where('charge_batch_id', $batchId)
            ->where('is_cancelled', false)
            ->with(['charge_status'])
            ->get();

        $totalExpected = $charges->sum(fn (Charge $c): int => (int) $c->getRawOriginal('expected_amount'));
        $totalCollected = $charges->sum(fn (Charge $c): int => (int) $c->getRawOriginal('paid_amount'));

        $completedStatus = ChargeStatus::find(ChargeStatus::COMPLETED);
        $overdueStatus = ChargeStatus::find(ChargeStatus::OVERDUE);

        $paidCount = $completedStatus
            ? $charges->where('charge_status_id', $completedStatus->id)->count()
            : 0;
        $overdueCount = $overdueStatus
            ? $charges->where('charge_status_id', $overdueStatus->id)->count()
            : 0;

        $progressPct = $totalExpected > 0
            ? round(($totalCollected / $totalExpected) * 100, 2)
            : 0.0;

        return [
            'batch_id' => $batchId,
            'total_charges' => $charges->count(),
            'paid_count' => $paidCount,
            'overdue_count' => $overdueCount,
            'pending_count' => $charges->count() - $paidCount - $overdueCount,
            'total_expected' => $totalExpected,
            'total_collected' => $totalCollected,
            'progress_pct' => $progressPct,
        ];
    }

    // =========================================================================
    // FINANCIAL
    // =========================================================================

    /**
     * F02 — Cash flow: income (confirmed payments) minus expenses (non-void)
     * over a period, bucketed monthly with a running balance.
     *
     * @return array{period_start: string, period_end: string, buckets: Collection, total_income: int, total_expenses: int, net: int}
     */
    public function cashFlow(string $periodStart, string $periodEnd): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();

        $confirmedStatus = PaymentStatus::find(PaymentStatus::CONFIRMED);

        $payments = Payment::query()
            ->when($confirmedStatus, fn ($q) => $q->where('payment_status_id', $confirmedStatus->id))
            ->whereBetween('paid_at', [$start, $end])
            ->get();

        $expenses = Expense::query()
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->whereBetween('paid_at', [$start, $end])
            ->get();

        $incomeByMonth = $payments->groupBy(fn (Payment $p): string => Carbon::parse($p->paid_at)->format('Y-m'))
            ->map(fn (Collection $group): int => $group->sum(fn (Payment $p): int => (int) $p->getRawOriginal('total_amount')));

        $expenseByMonth = $expenses->groupBy(fn (Expense $e): string => Carbon::parse($e->paid_at)->format('Y-m'))
            ->map(fn (Collection $group): int => $group->sum(fn (Expense $e): int => (int) $e->getRawOriginal('amount')));

        $months = $incomeByMonth->keys()->merge($expenseByMonth->keys())->unique()->sort()->values();

        $running = 0;
        $buckets = $months->map(function (string $month) use ($incomeByMonth, $expenseByMonth, &$running): array {
            $income = (int) ($incomeByMonth[$month] ?? 0);
            $expense = (int) ($expenseByMonth[$month] ?? 0);
            $net = $income - $expense;
            $running += $net;

            return [
                'period' => $month,
                'income' => $income,
                'expenses' => $expense,
                'net' => $net,
                'running_balance' => $running,
            ];
        });

        $totalIncome = (int) $incomeByMonth->sum();
        $totalExpenses = (int) $expenseByMonth->sum();

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'buckets' => $buckets,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net' => $totalIncome - $totalExpenses,
        ];
    }

    /**
     * F04 — Expenses by category: sum of non-void expenses grouped by category.
     *
     * @return array{period_start: string, period_end: string, categories: Collection, total_expenses: int}
     */
    public function expensesByCategory(string $periodStart, string $periodEnd): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();

        $expenses = Expense::query()
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->whereBetween('paid_at', [$start, $end])
            ->with(['category'])
            ->get();

        $categories = $expenses->groupBy('expense_category_id')->map(function (Collection $group): array {
            $first = $group->first();

            return [
                'category_id' => $first->expense_category_id,
                'category_name' => $first->category?->name ?? 'Sin categoría',
                'expense_count' => $group->count(),
                'total_amount' => $group->sum(fn (Expense $e): int => (int) $e->getRawOriginal('amount')),
            ];
        })->sortByDesc('total_amount')->values();

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'categories' => $categories,
            'total_expenses' => (int) $categories->sum('total_amount'),
        ];
    }

    /**
     * F05 — Annual fiscal summary: income, expenses by category, and net for a year.
     *
     * @return array{year: int, total_income: int, total_expenses: int, net: int, categories: Collection}
     */
    public function annualFiscal(int $year): array
    {
        $byCategory = $this->expensesByCategory("{$year}-01-01", "{$year}-12-31");

        $confirmedStatus = PaymentStatus::find(PaymentStatus::CONFIRMED);

        $totalIncome = (int) Payment::query()
            ->when($confirmedStatus, fn ($q) => $q->where('payment_status_id', $confirmedStatus->id))
            ->whereYear('paid_at', $year)
            ->sum('total_amount');

        $totalExpenses = (int) $byCategory['total_expenses'];

        return [
            'year' => $year,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net' => $totalIncome - $totalExpenses,
            'categories' => $byCategory['categories'],
        ];
    }

    // =========================================================================
    // COLLECTIONS
    // =========================================================================

    /**
     * C01 — Unit account statement: charges, payments, credit balance, totals
     * for a single unit. Resident-accessible for their OWN unit.
     *
     * @return array{unit_id: string, unit_number: string|null, charges: Collection, payments: Collection, credit_balance: int, total_expected: int, total_paid: int, total_outstanding: int}|null
     */
    public function unitStatement(string $unitId): ?array
    {
        $unit = Unit::find($unitId);

        if (! $unit) {
            return null;
        }

        $charges = Charge::query()
            ->where('unit_id', $unitId)
            ->where('is_cancelled', false)
            ->with(['concept', 'charge_status'])
            ->orderBy('due_date')
            ->get();

        $payments = Payment::query()
            ->where('unit_id', $unitId)
            ->with(['payment_status', 'payment_method'])
            ->orderByDesc('paid_at')
            ->get();

        $totalExpected = $charges->sum(fn (Charge $c): int => (int) $c->getRawOriginal('expected_amount'));
        $totalPaid = $charges->sum(fn (Charge $c): int => (int) $c->getRawOriginal('paid_amount'));

        $creditBalance = (int) CreditBalance::query()
            ->where('unit_id', $unitId)
            ->sum('amount');

        $chargeRows = $charges->map(fn (Charge $c): array => [
            'concept' => $c->concept?->name ?? '—',
            'due_date' => $c->due_date?->toDateString(),
            'expected_amount' => (int) $c->getRawOriginal('expected_amount'),
            'paid_amount' => (int) $c->getRawOriginal('paid_amount'),
            'outstanding' => (int) $c->getRawOriginal('expected_amount') - (int) $c->getRawOriginal('paid_amount'),
            'status' => $c->charge_status?->name ?? '—',
        ])->values();

        $paymentRows = $payments->map(fn (Payment $p): array => [
            'receipt_number' => $p->receipt_number,
            'paid_at' => $p->paid_at?->toDateString(),
            'method' => $p->payment_method?->name ?? '—',
            'total_amount' => (int) $p->getRawOriginal('total_amount'),
            'status' => $p->payment_status?->name ?? '—',
        ])->values();

        return [
            'unit_id' => $unit->id,
            'unit_number' => $unit->number,
            'charges' => $chargeRows,
            'payments' => $paymentRows,
            'credit_balance' => $creditBalance,
            'total_expected' => $totalExpected,
            'total_paid' => $totalPaid,
            'total_outstanding' => $totalExpected - $totalPaid,
        ];
    }

    /**
     * C02 — Global statement: per-unit expected/paid/outstanding/status summary.
     *
     * @return array{units: Collection, total_expected: int, total_paid: int, total_outstanding: int, unit_count: int}
     */
    public function globalStatement(): array
    {
        $charges = Charge::query()
            ->where('is_cancelled', false)
            ->with(['unit'])
            ->get();

        $byUnit = $charges->groupBy('unit_id')->map(function (Collection $group): array {
            $unit = $group->first()->unit;
            $expected = $group->sum(fn (Charge $c): int => (int) $c->getRawOriginal('expected_amount'));
            $paid = $group->sum(fn (Charge $c): int => (int) $c->getRawOriginal('paid_amount'));
            $outstanding = $expected - $paid;

            return [
                'unit_id' => $unit?->id,
                'unit_number' => $unit?->number ?? '—',
                'total_expected' => $expected,
                'total_paid' => $paid,
                'total_outstanding' => $outstanding,
                'status' => $outstanding <= 0 ? 'Al corriente' : 'Con adeudo',
            ];
        })->sortBy('unit_number')->values();

        return [
            'units' => $byUnit,
            'total_expected' => (int) $byUnit->sum('total_expected'),
            'total_paid' => (int) $byUnit->sum('total_paid'),
            'total_outstanding' => (int) $byUnit->sum('total_outstanding'),
            'unit_count' => $byUnit->count(),
        ];
    }

    /**
     * C05 — Payment history: all payments in a period.
     *
     * @return array{period_start: string, period_end: string, payments: Collection, total_amount: int, payment_count: int}
     */
    public function paymentHistory(string $periodStart, string $periodEnd): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();

        $payments = Payment::query()
            ->whereBetween('paid_at', [$start, $end])
            ->with(['unit', 'payment_method', 'payment_status'])
            ->orderByDesc('paid_at')
            ->get();

        $rows = $payments->map(fn (Payment $p): array => [
            'unit_number' => $p->unit?->number ?? '—',
            'paid_at' => $p->paid_at?->toDateString(),
            'receipt_number' => $p->receipt_number,
            'method' => $p->payment_method?->name ?? '—',
            'total_amount' => (int) $p->getRawOriginal('total_amount'),
            'status' => $p->payment_status?->name ?? '—',
        ])->values();

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'payments' => $rows,
            'total_amount' => (int) $rows->sum('total_amount'),
            'payment_count' => $rows->count(),
        ];
    }

    /**
     * C06 — Credit balances: all units with a positive credit balance.
     *
     * @return array{units: Collection, total_credit: int, unit_count: int}
     */
    public function creditBalances(): array
    {
        $balances = CreditBalance::query()
            ->with(['unit'])
            ->get()
            ->filter(fn (CreditBalance $cb): bool => (int) $cb->getRawOriginal('amount') > 0);

        $units = $balances->map(fn (CreditBalance $cb): array => [
            'unit_id' => $cb->unit_id,
            'unit_number' => $cb->unit?->number ?? '—',
            'credit_balance' => (int) $cb->getRawOriginal('amount'),
        ])->sortByDesc('credit_balance')->values();

        return [
            'units' => $units,
            'total_credit' => (int) $units->sum('credit_balance'),
            'unit_count' => $units->count(),
        ];
    }

    // =========================================================================
    // OPERATIONS
    // =========================================================================

    /**
     * O01 — Access log report: visits in a period.
     *
     * @return array{period_start: string, period_end: string, visits: Collection, visit_count: int}
     */
    public function accessLogReport(string $periodStart, string $periodEnd): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();

        $logs = AccessLog::query()
            ->whereBetween('entered_at', [$start, $end])
            ->with(['unit', 'guard_user'])
            ->orderByDesc('entered_at')
            ->get();

        $visits = $logs->map(fn (AccessLog $log): array => [
            'visitor_name' => $log->visitor_name ?? '—',
            'unit_number' => $log->unit?->number ?? '—',
            'entered_at' => $log->entered_at?->format('Y-m-d H:i'),
            'exited_at' => $log->exited_at?->format('Y-m-d H:i'),
            'guard' => $log->guard_user?->full_name ?? '—',
        ])->values();

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'visits' => $visits,
            'visit_count' => $visits->count(),
        ];
    }

    /**
     * O02 — Staff report: active employees (informational payroll).
     * Salary is sensitive — gate to admin/board (read.report) only.
     *
     * @return array{employees: Collection, employee_count: int, total_payroll: int}
     */
    public function staffReport(): array
    {
        $employees = Collaborator::query()
            ->where('is_active', true)
            ->whereNull('termination_date')
            ->with(['employee_position', 'employee_shift'])
            ->get();

        $rows = $employees->map(fn (Collaborator $e): array => [
            'name' => $e->full_name,
            'position' => $e->employee_position?->name ?? '—',
            'base_salary' => (int) $e->getRawOriginal('base_salary'),
            'shift' => $e->employee_shift?->name ?? '—',
        ])->values();

        return [
            'employees' => $rows,
            'employee_count' => $rows->count(),
            'total_payroll' => (int) $rows->sum('base_salary'),
        ];
    }

    /**
     * O03 — Provider history: payments (expenses) by provider in a period.
     *
     * @return array{period_start: string, period_end: string, providers: Collection, total_paid: int}
     */
    public function providerHistory(string $periodStart, string $periodEnd): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();

        $expenses = Expense::query()
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->whereNotNull('provider_id')
            ->whereBetween('paid_at', [$start, $end])
            ->with(['provider'])
            ->get();

        $providers = $expenses->groupBy('provider_id')->map(function (Collection $group): array {
            $provider = $group->first()->provider;

            return [
                'provider_id' => $provider?->id,
                'provider_name' => $provider?->name ?? '—',
                'payment_count' => $group->count(),
                'total_paid' => $group->sum(fn (Expense $e): int => (int) $e->getRawOriginal('amount')),
            ];
        })->sortByDesc('total_paid')->values();

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'providers' => $providers,
            'total_paid' => (int) $providers->sum('total_paid'),
        ];
    }

    /**
     * O04 — Project progress: community projects with funding + execution metrics.
     *
     * @return array{projects: Collection, project_count: int}
     */
    public function projectProgress(): array
    {
        $projects = CommunityProject::query()
            ->with(['status', 'contributions'])
            ->get();

        $rows = $projects->map(function (CommunityProject $project): array {
            $goal = (int) $project->getRawOriginal('goal_amount');
            $raised = $project->contributions->sum(fn ($c): int => (int) $c->getRawOriginal('contributed_amount'));
            $available = $goal - $raised;
            $pct = $goal > 0 ? round(($raised / $goal) * 100, 2) : 0.0;

            return [
                'project_id' => $project->id,
                'title' => $project->title,
                'status' => $project->status?->name ?? '—',
                'goal_amount' => $goal,
                'raised_amount' => $raised,
                'available_amount' => $available,
                'progress_pct' => $pct,
                'contribution_count' => $project->contributions->count(),
            ];
        })->values();

        return [
            'projects' => $rows,
            'project_count' => $rows->count(),
        ];
    }

    // =========================================================================
    // ADMINISTRATIVE
    // =========================================================================

    /**
     * A01 — Resident directory (padrón): units + their active residents.
     *
     * @return array{residents: Collection, resident_count: int}
     */
    public function residentDirectory(): array
    {
        $unitUsers = UnitUser::query()
            ->whereNull('end_date')
            ->with(['unit', 'user', 'unitRelationship'])
            ->get();

        $residents = $unitUsers->map(fn (UnitUser $uu): array => [
            'unit_number' => $uu->unit?->number ?? '—',
            'name' => $uu->user?->full_name ?? '—',
            'relationship' => $uu->unitRelationship?->name ?? '—',
            'phone' => $uu->user?->phone ?? '—',
            'email' => $uu->user?->email ?? '—',
        ])->sortBy('unit_number')->values();

        return [
            'residents' => $residents,
            'resident_count' => $residents->count(),
        ];
    }

    /**
     * A02 — Active board: current board period members.
     *
     * @return array{period: string|null, members: Collection, member_count: int}
     */
    public function activeBoard(): array
    {
        $currentPeriod = BoardPeriod::query()
            ->where('board_period_status_id', BoardPeriodStatus::CURRENT)
            ->first();

        if (! $currentPeriod) {
            return ['period' => null, 'members' => collect(), 'member_count' => 0];
        }

        $members = BoardMember::query()
            ->where('board_period_id', $currentPeriod->id)
            ->with(['user', 'board_role', 'board_period'])
            ->get();

        $rows = $members->map(fn (BoardMember $m): array => [
            'name' => $m->user?->full_name ?? '—',
            'role' => $m->board_role?->name ?? '—',
            'unit' => $m->unit_id ? (Unit::find($m->unit_id)?->number ?? '—') : '—',
        ])->values();

        return [
            'period' => $currentPeriod->name,
            'members' => $rows,
            'member_count' => $rows->count(),
        ];
    }

    /**
     * A03 — Announcements sent: published announcements in a period.
     *
     * @return array{period_start: string, period_end: string, announcements: Collection, announcement_count: int}
     */
    public function announcementsSent(string $periodStart, string $periodEnd): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->endOfDay();

        $announcements = Announcement::query()
            ->where('announcement_status_id', AnnouncementStatus::PUBLISHED)
            ->whereBetween('publish_at', [$start, $end])
            ->with(['type', 'priority', 'reads'])
            ->orderByDesc('publish_at')
            ->get();

        $rows = $announcements->map(function (Announcement $a): array {
            $channels = collect([
                'Push' => $a->channel_push,
                'Cartelera' => $a->channel_board,
                'Email' => $a->channel_email,
                'SMS' => $a->channel_sms,
            ])->filter()->keys()->implode(', ');

            return [
                'title' => $a->title,
                'type' => $a->type?->name ?? '—',
                'priority' => $a->priority?->name ?? '—',
                'channels' => $channels ?: '—',
                'read_count' => $a->reads->count(),
                'published_at' => $a->publish_at?->format('Y-m-d H:i'),
            ];
        })->values();

        return [
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'announcements' => $rows,
            'announcement_count' => $rows->count(),
        ];
    }
}
