<?php

namespace Modules\Housing\Observers;

use App\Notifications\Concerns\NotifiesResidents;
use Modules\Collections\Services\ChargeGenerationService;
use Modules\Housing\Models\Unit;
use Modules\SubscriptionPlan\Services\QuotaService;

/**
 * UnitObserver — domain hooks for Unit lifecycle.
 *
 * §6.7: email associated persons when a unit is marked inactive (updated).
 * §6.5 r4: auto-charge a newly created unit with the active period's matching cobros (created).
 * unit-limit-enforcement: reject an increase to the active unit count that would push
 * the tenant over its billing-band cap (creating/restoring).
 *
 * Domain observer; ordered BEFORE GlobalObserver on the model so audit stamping still
 * runs last (see laravel-backend-architecture §2.13). creating()/restoring() are the single choke point
 * for all 5 increase paths (store, batch, restore, batch/restore, importer chunk jobs) —
 * see UnitLimitService's class docblock for the tenancy/super-admin decision.
 */
class UnitObserver
{
    use NotifiesResidents;

    /**
     * unit-limit-enforcement: block a new unit BEFORE it is inserted when it
     * would push the tenant's active unit count above its plan's cap.
     * trial-usage-quotas (REQ-12): assertCanRegisterUnits() composes that SAME
     * band guard with the trial/active unit quota (D5), throwing whichever is
     * stricter — the band check runs first, so band-only behavior is
     * byte-identical while the quota stays seeded at -1 (REQ-QE-05).
     */
    public function creating(Unit $unit): void
    {
        app(QuotaService::class)->assertCanRegisterUnits(1);
    }

    /**
     * §6.5 r4: when a unit is created, auto-generate the active period's charges that
     * now target it. Delegated to ChargeGenerationService (TargetResolver + AmountCalculator,
     * idempotent). Guarded so a charge-generation failure NEVER aborts unit creation.
     */
    public function created(Unit $unit): void
    {
        try {
            app(ChargeGenerationService::class)->generateForNewUnit($unit);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * unit-limit-enforcement: restoring a soft-deleted unit increases the active
     * count exactly like creating one (REQ-03) — but `restoring` fires BEFORE
     * `deleted_at` is cleared (SoftDeletes::restore():170-172), so `$unit->trashed()`
     * still reflects the PRE-restore state here. Skip entirely when false so a
     * live unit id passed through Orion's `buildBatchRestoreFetchQuery()` (which
     * uses `withTrashed()`, NOT `onlyTrashed()`, HandlesStandardBatchOperations:465-467)
     * never wrongly consumes a band slot for a unit that was never dead.
     */
    public function restoring(Unit $unit): void
    {
        if (! $unit->trashed()) {
            return;
        }

        app(QuotaService::class)->assertCanRegisterUnits(1);
    }

    public function updated(Unit $unit): void
    {
        if (! $unit->wasChanged('unit_status_id')) {
            return;
        }

        // Solo avisar cuando la unidad pasa a un estado NO facturable
        // (ej. "Inactiva"): queda fuera de la operación de cobranza.
        $status = $unit->unitStatus()->first();
        if (! $status || $status->is_billable) {
            return;
        }

        $residentIds = $this->unitResidentUserIds($unit->id);
        if ($residentIds === []) {
            return;
        }

        $statusLabel = mb_strtolower($status->name);

        $this->notifyUsers(
            $residentIds,
            'Unidad marcada como '.$statusLabel,
            'Tu unidad fue marcada como '.$statusLabel.' y quedó fuera de la operación de cobranza. Contacta a la administración si tienes dudas.',
            ['click_action' => '/my-unit', 'unit_id' => $unit->id],
            channels: ['log', 'resend_email']
        );
    }
}
