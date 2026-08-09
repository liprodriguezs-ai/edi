<?php

namespace Modules\AmenityReservations\Services;

use Modules\AmenityReservations\Models\AmenityReservation;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeConcept;
use Modules\Collections\Models\ChargeConceptCategory;
use Modules\Collections\Models\ChargeStatus;
use Modules\Collections\Services\ChargeStatusService;

/**
 * AmenityChargeService — creates the standalone Collections charge that backs
 * a confirmed amenity-reservation earmark payment (S8).
 *
 * DEPOSIT = ANTICIPO (a down payment ON the cost, never a separate garantía):
 * the reservation's OWN amount_outstanding (seeded at S6 creation = the full
 * cost_amount snapshot) is the SOLE source of truth for what is still owed —
 * there is no deposit-return/forfeit money flow. Every confirmed earmark
 * payment gets its OWN standalone charge (charge_batch_id=null,
 * charge_policy_id=null), born PENDING and carried to COMPLETED the moment
 * PaymentService pins the payment's full amount to it (mirrors
 * Modules\Projects\Services\ProjectActivationService::createChargeForUnit /
 * Modules\Collections\Services\DirectChargeService — a "born paid, or not
 * born" charge, never a generate-now-pay-later gap FIFO could steal).
 */
class AmenityChargeService
{
    /**
     * Stable code of the SINGLE shared tenant concept every amenity-reservation
     * charge uses — lazily created on first use (unlike Projects, which mints a
     * disposable per-project concept, there is no per-reservation config to
     * carry here, so one shared concept suffices for all amenities/reservations).
     */
    private const CONCEPT_CODE = 'AMENITY_RESERVATION';

    public function __construct(
        private readonly ChargeStatusService $chargeStatusService,
    ) {}

    /**
     * Create a standalone charge for a confirmed earmark payment against a
     * reservation. Called by AmenityReservationEarmarkHandler::resolveApplications
     * INSIDE the payment's own transaction — the caller (PaymentService's
     * applyManual/applyToCharge) immediately pins the payment's full amount to
     * this charge in the SAME transaction, which is what carries it from
     * PENDING to COMPLETED.
     */
    public function createChargeForReservation(AmenityReservation $reservation, int $amountRaw): Charge
    {
        $today = now()->toDateString();

        $charge = Charge::create([
            'charge_concept_id' => $this->resolveConceptId(),
            'charge_policy_id' => null,
            'unit_id' => $reservation->unit_id,
            'expected_amount' => $amountRaw,
            'paid_amount' => 0,
            'issued_at' => $today,
            'due_date' => $today,
            'grace_days' => 0,
            'charge_status_id' => $this->chargeStatusService->resolveStatusId(ChargeStatus::PENDING),
            'is_cancelled' => false,
            'amenity_reservation_id' => $reservation->id,
        ]);

        // Recompute status (defensive — the born-PENDING status above already
        // matches the paid_amount=0 state; the payment's own application is
        // what carries it to COMPLETED, in the SAME transaction, right after).
        $this->chargeStatusService->recomputeCharge($charge, 0);

        return $charge;
    }

    /**
     * Resolve (or lazily create) the tenant's single shared "Reservación de
     * amenidad" concept. Mirrors
     * ProjectActivationService::resolveProjectChargeConcept, except ALL
     * amenity-reservation charges share ONE concept — there is no per-entity
     * disposable concept/policy pair to mint here.
     *
     * FIX (reviewer-confirmed LOW): firstOrCreate instead of a separate
     * where()->first() + create() — narrows the window where two concurrent
     * FIRST-EVER earmark validations for this tenant could both miss the
     * lookup and both attempt to create the row, tripping the
     * charge_concept_tenant_code_unique index.
     */
    private function resolveConceptId(): string
    {
        $concept = ChargeConcept::firstOrCreate(
            ['code' => self::CONCEPT_CODE, 'tenant_id' => tenant('id')],
            [
                'name' => 'Reservación de amenidad',
                // Seeded per-tenant by TenantCatalogSeeder (same row DirectChargeService's
                // "OTHER" concept relies on) — guaranteed present, so idOfKey() throwing
                // loud on a genuinely missing seed is the correct, established behavior.
                'charge_concept_category_id' => ChargeConceptCategory::idOfKey(ChargeConceptCategory::KEY_SERVICES),
                'is_active' => true,
            ]
        );

        return $concept->id;
    }
}
