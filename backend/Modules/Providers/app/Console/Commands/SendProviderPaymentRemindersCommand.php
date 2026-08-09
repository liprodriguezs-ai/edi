<?php

namespace Modules\Providers\Console\Commands;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Providers\Models\Provider;
use Modules\Tenant\Models\Tenant;
use Modules\Treasury\Models\Expense;
use Modules\Treasury\Models\ExpenseStatus;

/**
 * SendProviderPaymentRemindersCommand — daily cron (§10.5/§10.6).
 *
 * For each tenant, scans recurring providers with a usual_payment_day and
 * notifies the Tesorero. Payment-aware (providers-01): a provider with a
 * non-voided expense registered in the current month is considered paid and
 * never reminded. Idempotent: UPCOMING fires only on its exact lead day;
 * OVERDUE fires on the first day past grace and then re-alerts weekly —
 * never daily spam.
 */
class SendProviderPaymentRemindersCommand extends Command
{
    use NotifiesResidents;

    /** Days before usual_payment_day at which the upcoming reminder fires. */
    private const UPCOMING_LEAD_DAYS = 3;

    /** Grace days after usual_payment_day before the payment counts as overdue. */
    private const OVERDUE_GRACE_DAYS = 5;

    /** Cadence (days) for repeating the overdue alert while unpaid. */
    private const OVERDUE_REPEAT_DAYS = 7;

    protected $signature = 'providers:payment-reminders';

    protected $description = 'Daily: remind the Tesorero of upcoming and overdue recurring provider payments per tenant.';

    public function handle(): int
    {
        $tenants = Tenant::all();
        $today = Carbon::today();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($today, $tenant): void {
                $count = $this->sendForTenant($today);
                $label = $tenant->name ?? $tenant->id;
                $this->info("[{$label}] {$count} provider payment reminder(s) dispatched.");
            });
        }

        $this->info('providers:payment-reminders completed.');

        return self::SUCCESS;
    }

    private function sendForTenant(Carbon $today): int
    {
        $treasurerIds = $this->userIdsWithRole('treasurer');
        if ($treasurerIds === []) {
            return 0;
        }

        $providers = Provider::query()
            ->where('is_recurring', true)
            ->where('is_active', true)
            ->whereNotNull('usual_payment_day')
            ->get();

        $sent = 0;

        foreach ($providers as $provider) {
            // usual_payment_day is a 1–31 smallint. Clamp to this month's length so a
            // "day 31" provider is due on the last day of a short month (not overflowed
            // into the next via Carbon's day overflow).
            $paymentDay = max(1, min(31, (int) $provider->usual_payment_day));
            $dueThisMonth = $today->copy()->day(min($paymentDay, $today->daysInMonth));

            // UPCOMING: exactly UPCOMING_LEAD_DAYS before this month's due date, only if
            // this month's payment has not been registered yet.
            if ($today->isSameDay($dueThisMonth->copy()->subDays(self::UPCOMING_LEAD_DAYS))
                && ! $this->paidSince($provider, $dueThisMonth->copy()->startOfMonth())) {
                $this->notifyUsers(
                    $treasurerIds,
                    'Pago a proveedor próximo',
                    "El pago al proveedor '{$provider->name}' vence pronto (día {$paymentDay} del mes).",
                    [
                        'category' => NotificationCategory::SYSTEM,
                        'click_action' => '/provider',
                        'provider_id' => $provider->id,
                    ],
                    channels: ['log']
                );
                $sent++;

                continue;
            }

            // OVERDUE: anchor on the most recent due date ON OR BEFORE today (which may
            // fall in the previous month). This is what fixes late-month payment days:
            // the old day-of-month math computed firstOverdueDay = paymentDay + grace and
            // required dayOfMonth to reach it, so a day-28 provider (firstOverdueDay 33)
            // never fired. Using real dates lets the grace window roll into the next
            // month, where the alert correctly fires.
            $effectiveDue = $dueThisMonth->lessThanOrEqualTo($today)
                ? $dueThisMonth->copy()
                : $this->previousMonthDue($today, $paymentDay);

            // Paid since the effective due date → not overdue (providers-01). A
            // calendar-month check would wrongly flag a late-prev-month payment as unpaid
            // once the month rolls over.
            if ($this->paidSince($provider, $effectiveDue)) {
                continue;
            }

            $firstOverdue = $effectiveDue->copy()->addDays(self::OVERDUE_GRACE_DAYS);
            $daysOverdue = (int) $firstOverdue->diffInDays($today, false);
            if ($daysOverdue >= 0 && $daysOverdue % self::OVERDUE_REPEAT_DAYS === 0) {
                $this->notifyUsers(
                    $treasurerIds,
                    'Pago a proveedor vencido',
                    "El pago al proveedor '{$provider->name}' está vencido (debió pagarse el día {$paymentDay}).",
                    [
                        'category' => NotificationCategory::SYSTEM,
                        'click_action' => '/provider',
                        'provider_id' => $provider->id,
                    ],
                    channels: ['log', 'resend_email']
                );
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * The provider's due date in the month before $today, clamped to that month's
     * length (subMonthNoOverflow avoids Carbon's day overflow on short months).
     */
    private function previousMonthDue(Carbon $today, int $paymentDay): Carbon
    {
        $previous = $today->copy()->subMonthNoOverflow();

        return $previous->day(min($paymentDay, $previous->daysInMonth));
    }

    /**
     * Whether a non-voided expense for the provider was registered on or after $since.
     * Used both to skip the upcoming reminder when this month is already paid and to
     * skip the overdue reminder when the payment was made since its (possibly
     * previous-month) due date (providers-01). paid_at is a DATE column.
     */
    private function paidSince(Provider $provider, Carbon $since): bool
    {
        return Expense::query()
            ->where('provider_id', $provider->id)
            ->where('expense_status_id', '!=', ExpenseStatus::VOIDED)
            ->where('paid_at', '>=', $since->toDateString())
            ->exists();
    }
}
