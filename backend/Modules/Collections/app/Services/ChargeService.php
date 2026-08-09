<?php

namespace Modules\Collections\Services;

use Illuminate\Support\Facades\DB;
use Modules\Collections\Models\Charge;
use Modules\Collections\Models\ChargeBatch;

/**
 * ChargeService — charge-level domain operations beyond Orion CRUD.
 *
 * DISCOUNT / CONDONACIÓN (spec §8.2 — President/Admin only):
 *   Reduces a charge's owed amount WITHOUT registering a payment. The original
 *   expected_amount stays stored/visible; discount_amount accumulates the condoned
 *   minor units and discount_reason records the justification. A discount NEVER
 *   creates credit and NEVER touches the bank balance — it only lowers what is owed.
 *
 *   effective_expected = expected_amount − discount_amount (see ChargeStatusService).
 *   After a discount, the charge status and the unit aggregate are recomputed in the
 *   same transaction; a fully-condoned charge becomes COMPLETED.
 *
 * MONEY: minor units (integer centavos). The discount accumulates with a locked
 *   read-modify-write using raw ints — a DB::raw Expression cannot be routed through
 *   MoneyIntegerCast, so we never do that.
 */
class ChargeService
{
    public function __construct(
        private readonly ChargeStatusService $chargeStatusService,
        private readonly ChargeGenerationService $chargeGenerationService,
    ) {}

    /**
     * Cancel a charge (spec §8.7.2.4-5): requires a reason, forbidden once payments are
     * applied, and keeps the row in history. Runs in a transaction with a row lock and
     * recomputes the unit aggregate + batch progress (finding sec-money-10 — a cancelled
     * charge must never leave the unit frozen in a phantom 'overdue').
     *
     * @throws \InvalidArgumentException If already cancelled or payments are applied.
     */
    public function cancel(Charge $charge, string $reason): Charge
    {
        return DB::transaction(function () use ($charge, $reason): Charge {
            $locked = Charge::where('id', $charge->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_cancelled) {
                throw new \InvalidArgumentException('El cargo ya está cancelado.');
            }

            if ($locked->payment_applications()->exists()) {
                throw new \InvalidArgumentException(
                    'No se puede cancelar un cargo con pagos aplicados. Cancela el pago primero.'
                );
            }

            $locked->is_cancelled = true;
            $locked->cancellation_reason = $reason;
            $locked->save();

            // The cancelled charge no longer counts: refresh the unit worst-wins
            // aggregate and the batch progress in the SAME transaction.
            $this->chargeStatusService->recomputeUnitAggregate($locked->unit_id);

            if ($locked->charge_batch_id) {
                $batch = ChargeBatch::find($locked->charge_batch_id);
                if ($batch) {
                    $this->chargeGenerationService->recomputeBatchProgress($batch);
                }
            }

            return $locked->refresh()->load('charge_status');
        });
    }

    /**
     * Apply a discount (condonación) to a charge.
     *
     * @param  int  $amountRaw  Minor units to condone (must be > 0).
     * @param  string  $reason  Justification (required, audit trail).
     * @param  string|null  $userId  Central user id who authorized the discount.
     *
     * @throws \InvalidArgumentException If the charge is cancelled or the discount
     *                                   would exceed the still-owed (effective) amount.
     */
    public function applyDiscount(Charge $charge, int $amountRaw, string $reason, ?string $userId): Charge
    {
        if ($amountRaw <= 0) {
            throw new \InvalidArgumentException('El monto de condonación debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($charge, $amountRaw, $reason, $userId): Charge {
            $locked = Charge::where('id', $charge->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_cancelled) {
                throw new \InvalidArgumentException('No se puede condonar un cargo cancelado.');
            }

            $expectedRaw = (int) $locked->getAttributes()['expected_amount'];
            $currentDiscountRaw = (int) ($locked->getAttributes()['discount_amount'] ?? 0);
            $paidRaw = (int) $locked->getAttributes()['paid_amount'];

            // The new total discount may not exceed what is still owed after payments:
            // discount + paid must never surpass the original expected (no negative owed,
            // no condoning money already collected).
            $newDiscountRaw = $currentDiscountRaw + $amountRaw;
            if ($newDiscountRaw + $paidRaw > $expectedRaw) {
                throw new \InvalidArgumentException(
                    'La condonación no puede superar el saldo pendiente del cargo.'
                );
            }

            $locked->discount_amount = $newDiscountRaw;
            $locked->discount_reason = $reason;
            $locked->discounted_by_user_id = $userId;
            $locked->save();

            // Recompute status (a fully-condoned charge becomes completed) and the
            // unit aggregate + batch progress, all in this transaction.
            $this->chargeStatusService->recomputeCharge($locked);
            $this->chargeStatusService->recomputeUnitAggregate($locked->unit_id);

            if ($locked->charge_batch_id) {
                $batch = ChargeBatch::find($locked->charge_batch_id);
                if ($batch) {
                    $this->chargeGenerationService->recomputeBatchProgress($batch);
                }
            }

            return $locked->refresh()->load('charge_status');
        });
    }
}
