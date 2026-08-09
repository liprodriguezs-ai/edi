<?php

namespace Modules\Collections\Services;

use App\Notifications\Concerns\NotifiesResidents;
use App\Notifications\NotificationCategory;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargePolicy;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Models\ChargeSurchargeRule;
use Modules\Collections\Models\SurchargeMode;
use Modules\Collections\Support\CollectionSettings;
use Modules\Housing\Models\Unit;
use Modules\Notification\Services\NotificationSettingService;
use Modules\Setting\Models\Setting;

/**
 * ChargeStatusService — the single source of truth for charge status computation.
 *
 * Status priority (worst-wins for unit aggregate):
 *   overdue > partial > due_soon > overpaid > completed > pending
 *
 * STORED STATUS: every time a charge changes (payment applied, cancelled, reversed,
 * cron refresh), this service WRITES charge.charge_status_id in the SAME transaction.
 * Status is NEVER computed on-read; it is always stored.
 *
 * Thresholds:
 *   due_soon_days — from tenant_collection_setting.due_soon_days (default 7)
 *   overpayment_pct — from tenant_collection_setting.overpayment_pct (default 1%)
 *   These are read from Settings model; if not configured, defaults are used.
 */
class ChargeStatusService
{
    use NotifiesResidents;

    /**
     * Due-soon threshold in days (fall back to 7 if not configured).
     * Sourced from CollectionSettings so the default never drifts from the
     * seeder / config controller / cron.
     */
    private const DEFAULT_DUE_SOON_DAYS = CollectionSettings::DEFAULT_DUE_SOON_DAYS;

    /**
     * Grace threshold in days (fall back to the cluster default if not configured).
     * A charge whose grace_days snapshot is null resolves to the cluster
     * collections.grace_days (default 5) so a rule that left grace empty still honors
     * the cluster default instead of silently collapsing to 0 (moroso = due_date).
     */
    private const DEFAULT_GRACE_DAYS = CollectionSettings::DEFAULT_GRACE_DAYS;

    /**
     * Overpayment threshold: paid must exceed expected by this % to be "overpaid".
     * Default 1% (i.e. paid > expected * 1.01).
     */
    private const DEFAULT_OVERPAYMENT_PCT = 1.0;

    /**
     * Recompute and persist the status for a single Charge.
     *
     * MUST be called inside the same DB transaction that mutated the charge.
     *
     * @param  Charge  $charge  The charge to recompute (must have paid_amount current).
     * @param  int|null  $unitCreditRaw  Unused — kept for signature compatibility with
     *                                   existing call sites (e.g. Projects). Status is
     *                                   PER CHARGE; unit credit no longer affects it
     *                                   (finding collections-05).
     * @param  array{overpayment_pct: float, due_soon_days: int, grace_days: int}|null  $thresholds
     *                                                                                               Pre-resolved per-tenant thresholds (cron batch path);
     *                                                                                               resolved from Settings when null.
     */
    public function recomputeCharge(Charge $charge, ?int $unitCreditRaw = null, ?array $thresholds = null): void
    {
        if ($charge->is_cancelled) {
            // Cancelled charges keep their last status; no further transitions.
            return;
        }

        $statusKey = $this->resolveStatusKey($charge, $unitCreditRaw, $thresholds);
        $statusId = $this->resolveStatusId($statusKey);

        $charge->charge_status_id = $statusId;
        $charge->save();
    }

    /**
     * Recompute the unit aggregate status (worst-wins across all open charges).
     * Writes unit.charge_status_id.
     *
     * MUST be called inside the same DB transaction.
     */
    public function recomputeUnitAggregate(string $unitId): void
    {
        $unit = Unit::find($unitId);
        if (! $unit) {
            return;
        }

        // Collect all non-cancelled charge status ids for this unit.
        // charge_status_id IS the status (central catalog UUID), so no join needed.
        $statusIds = Charge::query()
            ->where('unit_id', $unitId)
            ->where('is_cancelled', false)
            ->pluck('charge_status_id');

        $unit->charge_status_id = $this->worstWins($statusIds);
        $unit->save();
    }

    /**
     * Batch version of recomputeUnitAggregate for many units at once.
     *
     * Avoids the N+1 of one Unit::find + one Charge query PER unit: fetches all
     * units' non-cancelled charge statuses in a SINGLE query, computes worst-wins
     * in PHP, then writes with one bulk UPDATE per distinct resulting status
     * (≤7 statements regardless of unit count). MUST run inside the same DB
     * transaction as the callers (cron refresh / batch activation).
     *
     * @param  array<int, string>  $unitIds
     */
    public function recomputeUnitAggregates(array $unitIds): void
    {
        $unitIds = array_values(array_unique(array_filter($unitIds)));
        if ($unitIds === []) {
            return;
        }

        // ONE query for every unit's open-charge statuses (no per-unit round trip).
        $statusesByUnit = Charge::query()
            ->whereIn('unit_id', $unitIds)
            ->where('is_cancelled', false)
            ->get(['unit_id', 'charge_status_id'])
            ->groupBy('unit_id');

        // Group units by their resulting worst-wins status so each distinct status
        // (including "no open charges" → null) is one bulk UPDATE.
        $unitIdsByStatus = [];
        foreach ($unitIds as $unitId) {
            $statusIds = ($statusesByUnit[$unitId] ?? collect())->pluck('charge_status_id');
            $worst = $this->worstWins($statusIds);
            $unitIdsByStatus[$worst ?? ''][] = $unitId;
        }

        $now = now();
        foreach ($unitIdsByStatus as $statusKey => $ids) {
            Unit::query()->whereIn('id', $ids)->update([
                'charge_status_id' => $statusKey === '' ? null : $statusKey,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Recompute status for ALL open (non-cancelled, non-completed) charges.
     * Used by the daily cron to refresh time-based transitions (pending→due_soon→overdue).
     *
     * SCALABILITY (finding sec-money-14): the per-tenant thresholds are resolved ONCE
     * per run (not per charge) and charges are walked in chunks so a large cluster
     * never loads its whole open ledger into memory.
     */
    public function refreshAllOpenCharges(): void
    {
        $thresholds = $this->resolveThresholds();

        $affectedUnitIds = [];
        $newlyOverdueByUnit = [];

        // Only charges that could transition due to time. The constants ARE the
        // charge_status ids (central catalog), so filter on charge_status_id directly.
        // Eager-load the policy + its surcharge rules so the late-fee accrual below runs
        // without an N+1 per charge.
        Charge::query()
            ->with('policy.surcharge_rules')
            ->whereIn('charge_status_id', [
                ChargeStatus::PENDING,
                ChargeStatus::DUE_SOON,
                ChargeStatus::PARTIAL,
                ChargeStatus::OVERDUE,
            ])
            ->where('is_cancelled', false)
            ->chunkById(500, function ($charges) use (&$affectedUnitIds, &$newlyOverdueByUnit, $thresholds): void {
                foreach ($charges as $charge) {
                    $previousStatusId = $charge->charge_status_id;

                    // Accrue the surcharge (save #1) and recompute the status (save #2)
                    // as ONE atomic unit per charge. accrueSurcharge's contract requires
                    // running inside the same transaction as the status refresh; the cron
                    // path must honour it so a charge can never persist a fresh
                    // surcharge_amount with a stale charge_status_id if the second save
                    // fails (transient error / deadlock). Per-charge scope keeps the
                    // run idempotent — a failed charge simply retries next cycle.
                    DB::transaction(function () use ($charge, $thresholds): void {
                        // Accrue the late-fee surcharge FIRST (derived, idempotent) so the
                        // status recompute below sees the up-to-date effective_expected.
                        $this->accrueSurcharge($charge, $charge->policy, $thresholds);

                        $this->recomputeCharge($charge, null, $thresholds);
                    });

                    $affectedUnitIds[$charge->unit_id] = true;

                    // §8.9: a charge that just transitioned INTO overdue triggers a push to the
                    // unit's titular. Detected here (daily cron) so payment-time recomputes never
                    // notify, and a single bucket per unit avoids one push per overdue charge.
                    if (
                        $charge->charge_status_id === ChargeStatus::OVERDUE
                        && $previousStatusId !== ChargeStatus::OVERDUE
                    ) {
                        $newlyOverdueByUnit[$charge->unit_id] = ($newlyOverdueByUnit[$charge->unit_id] ?? 0) + 1;
                    }
                }
            });

        $this->recomputeUnitAggregates(array_keys($affectedUnitIds));

        $this->notifyNewlyOverdue($newlyOverdueByUnit);
    }

    /**
     * Notify the titular de pago of each unit whose charges just became overdue.
     *
     * @param  array<string, int>  $newlyOverdueByUnit  unit_id => count of newly-overdue charges
     */
    private function notifyNewlyOverdue(array $newlyOverdueByUnit): void
    {
        // Resolve the per-cluster notification config ONCE; gate on is_active and
        // reuse the resolved settings for every unit via notifyEvent() $preResolved.
        $resolved = app(NotificationSettingService::class)->resolve('charge_overdue');
        if (! $resolved['is_active']) {
            return;
        }

        foreach ($newlyOverdueByUnit as $unitId => $count) {
            $titularId = $this->unitBillingUserId($unitId);
            if (! $titularId) {
                continue;
            }

            // Fallback only: the seeded notification_template.charge_overdue (count shape)
            // wins over this literal whenever the template row exists.
            $fallbackBody = $count === 1
                ? 'Tienes un cargo vencido. Regulariza tu pago para evitar recargos.'
                : "Tienes {$count} cargos vencidos. Regulariza tus pagos para evitar recargos.";

            $this->notifyEvent(
                'charge_overdue',
                [$titularId],
                [
                    'count' => $count,
                ],
                'Cargo vencido',
                $fallbackBody,
                [
                    'category' => NotificationCategory::COLLECTIONS,
                    'click_action' => '/statement',
                    'unit_id' => $unitId,
                ],
                $resolved,
            );
        }
    }

    /**
     * Compute the status key for a charge given current state.
     *
     * @param  int|null  $unitCreditRaw  Unused — kept for signature compatibility.
     *                                   Status is PER CHARGE: unit-level credit must
     *                                   never freeze an unpaid charge as OVERPAID and
     *                                   hide it from the delinquency pipeline
     *                                   (finding collections-05). Credit visibility for
     *                                   residents comes from the statement endpoint, and
     *                                   credit is auto-applied at charge generation
     *                                   (Flujo C.5), so no status hack is needed.
     * @param  array{overpayment_pct: float, due_soon_days: int, grace_days: int}|null  $thresholds
     */
    public function resolveStatusKey(Charge $charge, ?int $unitCreditRaw = null, ?array $thresholds = null): string
    {
        $today = Carbon::today();
        $paidRaw = (int) $charge->getAttributes()['paid_amount'];

        // §8.2: a discount (condonación) reduces what is owed without being a payment.
        // The EFFECTIVE expected is what the unit must still cover. The original
        // expected_amount stays stored/visible; only the effective amount drives status.
        $expectedRaw = $this->effectiveExpected($charge);

        $thresholds ??= $this->resolveThresholds();
        $overpaymentPct = $thresholds['overpayment_pct'];
        // Per-policy override wins; null falls back to the cluster setting.
        $dueSoonDays = $charge->due_soon_days ?? $thresholds['due_soon_days'];

        $dueDate = $charge->due_date instanceof Carbon ? $charge->due_date : Carbon::parse($charge->due_date);
        // Per-policy snapshot wins; a null snapshot falls back to the cluster grace
        // setting (default 5) so a rule that left grace empty still honors the cluster
        // default instead of silently collapsing to 0 (moroso = due_date).
        $graceDays = (int) ($charge->grace_days ?? $thresholds['grace_days']);
        $overdueDate = $dueDate->copy()->addDays($graceDays);

        // FULLY CONDONED: a discount that wipes out the entire owed amount leaves
        // nothing to collect → completed (spec §8.2: full condonación → completed with 0 paid).
        if ($expectedRaw === 0) {
            return ChargeStatus::COMPLETED;
        }

        // COMPLETED: paid >= effective expected (after discount).
        if ($expectedRaw > 0 && $paidRaw >= $expectedRaw) {
            // Overpaid is PER CHARGE: this charge's own paid_amount must exceed expected
            // beyond the tolerance. Unrelated unit-level credit must NOT flip an exactly-paid
            // charge to overpaid — that would wrongly exclude it from batch progress (which
            // counts only completed charges) and show a fully collected batch as < 100%.
            $overpaymentThreshold = (int) ($expectedRaw * (1 + $overpaymentPct / 100.0));
            if ($paidRaw > $overpaymentThreshold) {
                return ChargeStatus::OVERPAID;
            }

            return ChargeStatus::COMPLETED;
        }

        // OVERDUE: past grace period with unpaid/partial balance.
        if ($today->isAfter($overdueDate) && $paidRaw < $expectedRaw) {
            return ChargeStatus::OVERDUE;
        }

        // PARTIAL: some payment but not complete, still within term.
        if ($paidRaw > 0 && $paidRaw < $expectedRaw) {
            return ChargeStatus::PARTIAL;
        }

        // DUE SOON: within due_soon_days of due_date, no payment yet.
        $dueSoonDate = $dueDate->copy()->subDays($dueSoonDays);
        if ($today->greaterThanOrEqualTo($dueSoonDate) && $today->lessThanOrEqualTo($dueDate) && $paidRaw === 0) {
            return ChargeStatus::DUE_SOON;
        }

        return ChargeStatus::PENDING;
    }

    /**
     * Per-tenant status thresholds from the slug-based Setting model. getValue returns
     * null when the row is absent for this tenant → fall back to the documented default.
     * Only a missing/unavailable `setting` TABLE (QueryException) is tolerated; any other
     * error (e.g. a genuinely missing method) must surface rather than be swallowed.
     *
     * @return array{overpayment_pct: float, due_soon_days: int, grace_days: int}
     */
    public function resolveThresholds(): array
    {
        try {
            return [
                'overpayment_pct' => (float) (Setting::getValue('collections.overpayment_pct') ?? self::DEFAULT_OVERPAYMENT_PCT),
                'due_soon_days' => (int) (Setting::getValue('collections.due_soon_days') ?? self::DEFAULT_DUE_SOON_DAYS),
                'grace_days' => (int) (Setting::getValue(CollectionSettings::GRACE_DAYS) ?? self::DEFAULT_GRACE_DAYS),
            ];
        } catch (QueryException) {
            // The setting table is absent (e.g. an isolated unit-test context); use defaults.
            return [
                'overpayment_pct' => self::DEFAULT_OVERPAYMENT_PCT,
                'due_soon_days' => self::DEFAULT_DUE_SOON_DAYS,
                'grace_days' => self::DEFAULT_GRACE_DAYS,
            ];
        }
    }

    /**
     * Effective expected amount in minor units: (expected_amount minus any discount),
     * floored at 0, PLUS the accrued late-fee surcharge.
     *
     * A condonación (§8.2) lowers what the unit must still cover; it is NOT a payment
     * and must never create credit. The surcharge (§3.6) ADDS to what is owed once the
     * charge is overdue. expected_amount itself stays frozen — the surcharge lives in a
     * separate ledger column and is recomputed daily. Floored before adding surcharge so
     * an over-large discount can't make the principal negative, while a positive
     * surcharge still raises the owed amount. This is the single source of truth for
     * "what is owed" — both status computation and FIFO remaining-balance use it.
     */
    public function effectiveExpected(Charge $charge): int
    {
        $expectedRaw = (int) $charge->getAttributes()['expected_amount'];
        $discountRaw = (int) ($charge->getAttributes()['discount_amount'] ?? 0);
        $surchargeRaw = (int) ($charge->getAttributes()['surcharge_amount'] ?? 0);

        return max(0, $expectedRaw - $discountRaw) + $surchargeRaw;
    }

    /**
     * Accrue (recompute, from scratch) the late-fee surcharge for a single charge.
     *
     * DERIVED & IDEMPOTENT: the surcharge_amount is recomputed deterministically every
     * day — it is NEVER incremented, so re-running the cron the same day yields the same
     * value. It NEVER mutates expected_amount; the result is written to the separate
     * surcharge_amount ledger column.
     *
     * Rules (all minor-unit integer arithmetic):
     *   - No policy (charge_policy_id null) → surcharge = 0 (no-op).
     *   - overdueDate = due_date + grace_days. Before overdueDate → 0 (no fee in grace).
     *   - base = max(0, (expected - discount) - paid). NOTE: base uses (expected - discount),
     *     NOT (expected - discount + surcharge) → NO compound interest. base = 0 (paid off)
     *     → surcharge = 0 (the fee STOPS the moment the balance is cleared).
     *   - monthsOverdue = wholeMonthsBetween(overdueDate, today) + 1 (1-INDEXED): the fee
     *     applies APENAS VENCE — day 1 of delinquency is period 1, ~day 31 is period 2.
     *   - rule = the active rule with the greatest from_month <= monthsOverdue. None → 0.
     *   - percent → base * value / 10000 (basis points); fixed → value (minor units).
     *   - recurrence per_month_overdue scales by monthsOverdue; one_time applies once.
     *   - cap_amount (when not null) caps the total.
     *
     * MUST be called inside the same DB transaction as the surrounding refresh.
     */
    public function accrueSurcharge(Charge $charge, ?ChargePolicy $policy, ?array $thresholds = null): void
    {
        $surcharge = $this->computeSurcharge($charge, $policy, $thresholds);

        // Persist only when the derived value or the stamp changed — keeps the daily
        // run cheap and avoids churning updated_at on charges with no fee movement.
        $currentSurcharge = (int) ($charge->getAttributes()['surcharge_amount'] ?? 0);
        $today = Carbon::today()->toDateString();
        $currentStamp = $charge->getAttributes()['surcharge_recomputed_at'] ?? null;
        $currentStamp = $currentStamp ? Carbon::parse($currentStamp)->toDateString() : null;

        if ($currentSurcharge === $surcharge && $currentStamp === $today) {
            return;
        }

        $charge->surcharge_amount = $surcharge;
        $charge->surcharge_recomputed_at = $today;
        $charge->save();
    }

    /**
     * Pure computation of the late-fee surcharge in minor units for a charge given its
     * policy. No persistence — see accrueSurcharge for the write side.
     */
    private function computeSurcharge(Charge $charge, ?ChargePolicy $policy, ?array $thresholds = null): int
    {
        // No policy attached → no surcharge schedule applies.
        if ($charge->charge_policy_id === null || $policy === null) {
            return 0;
        }

        $today = Carbon::today();

        $dueDate = $charge->due_date instanceof Carbon ? $charge->due_date->copy() : Carbon::parse($charge->due_date);
        // Same grace resolution as the status FSM: a null snapshot resolves to the
        // cluster grace default so the fee starts after due_date + effective grace.
        $graceDays = (int) ($charge->grace_days ?? ($thresholds ?? $this->resolveThresholds())['grace_days']);
        $overdueDate = $dueDate->copy()->addDays($graceDays);

        // Within grace (or not yet due) → no fee.
        if ($today->lessThanOrEqualTo($overdueDate)) {
            return 0;
        }

        // Base = principal still owed (expected - discount - paid). Uses the NON-surcharged
        // principal on purpose: the fee is charged on the original debt, never on prior fees
        // (no compounding). A cleared balance (base 0) stops the fee immediately.
        $expectedRaw = (int) $charge->getAttributes()['expected_amount'];
        $discountRaw = (int) ($charge->getAttributes()['discount_amount'] ?? 0);
        $paidRaw = (int) $charge->getAttributes()['paid_amount'];
        $base = max(0, max(0, $expectedRaw - $discountRaw) - $paidRaw);

        if ($base === 0) {
            return 0;
        }

        // Months overdue, 1-INDEXED: the surcharge applies the moment the charge passes
        // due_date + grace_days (product decision — el recargo aplica APENAS VENCE, no tras
        // un mes completo de mora). Day 1 of delinquency = period 1. We pass the grace gate
        // above (today > overdueDate), so monthsOverdue is always >= 1 here:
        //   - day 1 overdue → wholeMonthsBetween = 0 → +1 = 1 (a from_month=1 rule fires).
        //   - ~day 31 overdue → wholeMonthsBetween = 1 → +1 = 2.
        // per_month_overdue multiplies by this count; one_time applies once.
        $monthsOverdue = $this->wholeMonthsBetween($overdueDate, $today) + 1;

        $rule = $this->selectSurchargeRule($policy, $monthsOverdue);
        if ($rule === null) {
            return 0;
        }

        $surcharge = $this->surchargeForRule($rule, $base, $monthsOverdue);

        $capRaw = $rule->cap_amount;
        if ($capRaw !== null) {
            $surcharge = min($surcharge, (int) $capRaw);
        }

        return max(0, $surcharge);
    }

    /**
     * Whole (completed) calendar months from $from to $to. A $to before/at $from yields 0.
     *
     * CALENDAR-MONTH semantics: diffInMonths counts only COMPLETED month boundaries, and
     * Carbon v3 snaps to the calendar — so a charge whose overdueDate falls on a long-month
     * day (e.g. the 31st) reaches "month 2" roughly 3 days late relative to a naive 30-day
     * count, because the next month has no 31st to land on. The drift is at most a few days,
     * always pro-residente (the fee escalates slightly LATER, never earlier), deterministic,
     * and identical across reruns — accepted by the team.
     */
    private function wholeMonthsBetween(Carbon $from, Carbon $to): int
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        // diffInMonths counts only COMPLETED months between the two dates.
        return (int) $from->diffInMonths($to);
    }

    /**
     * The applicable rule: the active rule with the greatest from_month that is still
     * <= monthsOverdue. Returns null when no active rule has started yet.
     */
    private function selectSurchargeRule(ChargePolicy $policy, int $monthsOverdue): ?ChargeSurchargeRule
    {
        return $policy->surcharge_rules
            ->filter(fn (ChargeSurchargeRule $rule): bool => $rule->is_active && (int) $rule->from_month <= $monthsOverdue)
            ->sortByDesc(fn (ChargeSurchargeRule $rule): int => (int) $rule->from_month)
            ->first();
    }

    /**
     * Surcharge minor units for one rule, before cap.
     *
     * percent: base * basisPoints / 10000. fixed: the flat value.
     * per_month_overdue multiplies by monthsOverdue; one_time applies once.
     */
    private function surchargeForRule(ChargeSurchargeRule $rule, int $base, int $monthsOverdue): int
    {
        $valueRaw = (int) $rule->surcharge_value;

        $perUnit = $rule->surcharge_mode_id === SurchargeMode::PERCENT
            // floor deliberado: el recargo % nunca sobre-cobra (pro-residente)
            ? intdiv($base * $valueRaw, 10000)
            : $valueRaw;

        $multiplier = $rule->recurrence === ChargeSurchargeRule::RECURRENCE_PER_MONTH_OVERDUE
            ? $monthsOverdue
            : 1;

        return $perUnit * $multiplier;
    }

    /**
     * The ChargeStatus.id for a given status constant.
     *
     * ChargeStatus constants ARE the UUID id (central catalog), so this is the
     * identity — kept as a named method for readability at call sites.
     */
    public function resolveStatusId(string $statusId): string
    {
        return $statusId;
    }

    /**
     * Pick the "worst" status id from a collection of charge_status ids.
     * The ChargeStatus constants ARE the central-catalog UUID ids.
     *
     * @param  Collection<int, string|null>  $statusIds
     */
    private function worstWins(Collection $statusIds): ?string
    {
        $statusIds = $statusIds->filter();
        if ($statusIds->isEmpty()) {
            return null;
        }

        $priority = [
            ChargeStatus::OVERDUE => 0,
            ChargeStatus::PARTIAL => 1,
            ChargeStatus::DUE_SOON => 2,
            ChargeStatus::OVERPAID => 3,
            ChargeStatus::COMPLETED => 4,
            ChargeStatus::PENDING => 5,
        ];

        return $statusIds->sortBy(fn (string $id) => $priority[$id] ?? 99)->first();
    }
}
