<?php

namespace Modules\DataImport\Services;

/**
 * RowResult — immutable DTO returned by RowImporter::execute().
 *
 * Carries per-row execution outcome including idempotency flags,
 * counts of entities created, and any warnings emitted.
 */
class RowResult
{
    /**
     * @param  list<array{row: int, severity: string, column: string, message: string}>  $issues
     */
    public function __construct(
        /** True when the row was skipped due to idempotency match (payment already exists). */
        public readonly bool $skipped,
        /** True when a new unit was created for this row. */
        public readonly bool $unitCreated,
        /** True when a new user / unit_user link was created for this row. */
        public readonly bool $residentCreated,
        /** True when a payment was registered for this row. */
        public readonly bool $paymentRegistered,
        /** The unit_id resolved or created for this row (for aggregate recompute). */
        public readonly ?string $unitId,
        /** The bank_account_id used for this payment (for balance delta calculation). */
        public readonly ?string $bankAccountId,
        /** The total_amount (minor units) of the payment, when registered. */
        public readonly int $totalAmount,
        /** Per-row warnings and any execution issues. */
        public readonly array $issues,
    ) {}
}
