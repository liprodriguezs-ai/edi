<?php

namespace Modules\Treasury\Console\Commands;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Collections\Models\Payment;
use Modules\Collections\Models\PaymentStatus;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseStatus;
use QCH\Core\Support\TenantMoney;

/**
 * SendMonthlyFinancialSummaryCommand — monthly cron (§9.8).
 *
 * On the 1st of each month, emails the current board a summary of the previous month's
 * income (confirmed payments) and expenses (paid, non-voided). Routes through the
 * dispatcher (log + email drivers) and records an in-app notification per board member.
 */
class SendMonthlyFinancialSummaryCommand extends Command
{
    use NotifiesResidents;

    protected $signature = 'treasury:monthly-summary';

    protected $description = 'Monthly: email the board a financial summary of the previous month per tenant.';

    public function handle(): int
    {
        $tenants = Tenant::all();
        $period = Carbon::now()->subMonthNoOverflow();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($period, $tenant): void {
                $this->sendForTenant($period);
                $label = $tenant->name ?? $tenant->id;
                $this->info("[{$label}] Monthly financial summary dispatched.");
            });
        }

        $this->info('treasury:monthly-summary completed.');

        return self::SUCCESS;
    }

    private function sendForTenant(Carbon $period): void
    {
        $boardUserIds = $this->currentBoardMemberUserIds();
        if ($boardUserIds === []) {
            return;
        }

        $income = (int) Payment::query()
            ->where('payment_status_id', PaymentStatus::CONFIRMED)
            ->whereYear('paid_at', $period->year)
            ->whereMonth('paid_at', $period->month)
            ->sum('total_amount');

        // Bucketed by paid_at — the canonical month axis for expenses, shared
        // with PublicSummaryController so the email and the public view agree.
        $expenses = (int) Expense::query()
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->whereYear('paid_at', $period->year)
            ->whereMonth('paid_at', $period->month)
            ->sum('amount');

        $net = $income - $expenses;

        $body = sprintf(
            'Resumen de %s — Ingresos: %s, Egresos: %s, Resultado: %s.',
            $period->translatedFormat('F Y'),
            $this->formatMoney($income),
            $this->formatMoney($expenses),
            $this->formatMoney($net),
        );

        $this->notifyUsers(
            $boardUserIds,
            'Resumen financiero mensual',
            $body,
            [
                'click_action' => '/tableros/tesoreria',
                'period' => $period->format('Y-m'),
                'category' => NotificationCategory::REPORTS,
            ],
            channels: ['log', 'resend_email']
        );
    }

    private function formatMoney(int $amountRaw): string
    {
        return TenantMoney::format($amountRaw);
    }
}
