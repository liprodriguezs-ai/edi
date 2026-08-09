<?php

namespace Modules\Collections\Console\Commands;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeStatus;
use Modules\Setting\Models\Setting;
use Modules\Tenant\Models\Tenant;
use QCH\Core\Support\TenantMoney;

/**
 * SendChargeRemindersCommand — daily cron (§8.9).
 *
 * For each tenant, finds open (non-cancelled, not fully paid) charges and dispatches a
 * reminder push/email to the unit's titular de pago at the configured offsets:
 *   - N days BEFORE due_date (e.g. 7 and 3 days before)
 *   - ON the due_date itself
 *   - every M days while in arrears (overdue), e.g. every 15 days past due_date
 *
 * Offsets are read from the per-tenant collections.reminders JSON setting; documented
 * defaults are used when the setting is absent:
 *   { "before_due_days": [7, 3], "on_due_day": true, "overdue_every_days": 15 }
 */
class SendChargeRemindersCommand extends Command
{
    use NotifiesResidents;

    /** @var array{before_due_days: int[], on_due_day: bool, overdue_every_days: int} */
    private const DEFAULT_REMINDERS = [
        'before_due_days' => [7, 3],
        'on_due_day' => true,
        'overdue_every_days' => 15,
    ];

    protected $signature = 'collections:send-reminders';

    protected $description = 'Daily: send charge reminders (before due, on due day, and while in arrears) per tenant.';

    public function handle(): int
    {
        $tenants = Tenant::all();
        $today = Carbon::today();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($today, $tenant): void {
                $sent = $this->sendForTenant($today);
                $label = $tenant->name ?? $tenant->id;
                $this->info("[{$label}] {$sent} charge reminder(s) dispatched.");
            });
        }

        $this->info('collections:send-reminders completed.');

        return self::SUCCESS;
    }

    /**
     * Dispatch reminders for the current tenant. Returns the number of reminders sent.
     */
    private function sendForTenant(Carbon $today): int
    {
        $config = $this->resolveReminderConfig();

        $openCharges = Charge::query()
            ->where('is_cancelled', false)
            ->whereColumn('paid_amount', '<', 'expected_amount')
            ->whereNotIn('charge_status_id', [ChargeStatus::COMPLETED, ChargeStatus::OVERPAID])
            ->get();

        $sent = 0;

        foreach ($openCharges as $charge) {
            $offset = $this->matchedOffset($charge, $today, $config);
            if ($offset === null) {
                continue;
            }

            $titularId = $this->unitBillingUserId($charge->unit_id);
            if (! $titularId) {
                continue;
            }

            // Resolve context data for the configurable email template.
            $charge->loadMissing(['concept', 'unit.unitType', 'unit.tower']);
            $billingUser = User::withoutGlobalScope(UserTenantScope::class)->withTrashed()->find($titularId);
            $residentName = $billingUser
                ? (trim($billingUser->full_name) ?: ($billingUser->name ?? 'Residente'))
                : 'Residente';
            $unitLabel = $charge->unit?->full_identifier ?? 'Unidad';
            $conceptName = $charge->concept?->name ?? 'Cargo';
            $amountFormatted = TenantMoney::format((int) $charge->getAttributes()['expected_amount']);
            $dueDate = $charge->due_date instanceof Carbon
                ? $charge->due_date->copy()->startOfDay()
                : Carbon::parse((string) $charge->due_date)->startOfDay();
            $dueDateFormatted = $dueDate->format('d/m/Y');
            // arch W2: diffInDays(false) is SIGNED — negative for overdue charges.
            // days_left is clamped to 0 (never negative in the template); days_past_due
            // holds the absolute overdue count for the overdue-body copy.
            $signedDaysLeft = (int) $today->diffInDays($dueDate, false);
            $daysLeft = max(0, $signedDaysLeft);
            $daysPastDue = $signedDaysLeft < 0 ? abs($signedDaysLeft) : 0;
            $url = rtrim((string) config('app.frontend_url', ''), '/').'/cobranza';

            $this->notifyEvent(
                'payment_due_reminder',
                [$titularId],
                [
                    'resident_name' => $residentName,
                    'concept_name' => $conceptName,
                    'amount' => $amountFormatted,
                    'due_date' => $dueDateFormatted,
                    'days_left' => $daysLeft,
                    'days_past_due' => $daysPastDue,
                    'url' => $url,
                ],
                'Recordatorio de pago',
                $this->reminderBody($offset),
                [
                    'category' => NotificationCategory::COLLECTIONS,
                    'click_action' => '/statement',
                    'charge_id' => $charge->id,
                    'unit_id' => $charge->unit_id,
                ],
            );

            $sent++;
        }

        return $sent;
    }

    /**
     * Whether today matches a configured reminder offset for the given charge.
     * Returns a descriptor: 'before:N', 'due', or 'overdue', or null when no match.
     *
     * @param  array{before_due_days: int[], on_due_day: bool, overdue_every_days: int}  $config
     */
    private function matchedOffset(Charge $charge, Carbon $today, array $config): ?string
    {
        $dueDate = $charge->due_date instanceof Carbon ? $charge->due_date->copy() : Carbon::parse($charge->due_date);
        $dueDate = $dueDate->startOfDay();

        // Carbon 3 diffInDays returns FLOAT; both dates are startOfDay so the diff is
        // a whole number — cast once so the strict === 0 comparison below works
        // (finding collections-10: 0.0 === 0 is false, the due-day reminder never fired).
        $daysUntilDue = (int) $today->diffInDays($dueDate, false);

        // Before due: N days before due_date.
        if ($daysUntilDue > 0 && in_array($daysUntilDue, $config['before_due_days'], true)) {
            return 'before:'.$daysUntilDue;
        }

        // On the due day.
        if ($daysUntilDue === 0 && $config['on_due_day']) {
            return 'due';
        }

        // In arrears: every M days after due_date.
        if ($daysUntilDue < 0) {
            $daysPastDue = abs($daysUntilDue);
            $interval = max(1, $config['overdue_every_days']);
            if ($daysPastDue % $interval === 0) {
                return 'overdue';
            }
        }

        return null;
    }

    private function reminderBody(string $offset): string
    {
        if ($offset === 'due') {
            return 'Tu cargo vence hoy. Realiza tu pago para mantenerte al corriente.';
        }

        if ($offset === 'overdue') {
            return 'Tu cargo sigue vencido. Regulariza tu pago para evitar recargos.';
        }

        $days = (int) substr($offset, strlen('before:'));

        return "Tu cargo vence en {$days} día(s). No olvides realizar tu pago.";
    }

    /**
     * Resolve reminder offsets from the per-tenant setting, falling back to defaults.
     *
     * @return array{before_due_days: int[], on_due_day: bool, overdue_every_days: int}
     */
    private function resolveReminderConfig(): array
    {
        $raw = Setting::getValue('collections.reminders');

        if (! is_array($raw)) {
            return self::DEFAULT_REMINDERS;
        }

        return [
            'before_due_days' => array_map('intval', $raw['before_due_days'] ?? self::DEFAULT_REMINDERS['before_due_days']),
            'on_due_day' => (bool) ($raw['on_due_day'] ?? self::DEFAULT_REMINDERS['on_due_day']),
            'overdue_every_days' => (int) ($raw['overdue_every_days'] ?? self::DEFAULT_REMINDERS['overdue_every_days']),
        ];
    }
}
