<?php

declare(strict_types=1);

namespace Modules\ChildRecord\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\ChildRecord\Models\Visit;
use Modules\ChildRecord\Models\VisitReceiptSequence;

/**
 * REQ-CH-008 — generates the per-tenant, per-year `V-YYYY-NNNNNN` receipt
 * number under a row lock (D9), and keeps it immutable after create
 * (performUpdate strips it). Verbatim shape of the QuotaService::consume()
 * lock-then-recheck pattern already established in this codebase.
 */
class VisitService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Visit
    {
        return DB::transaction(function () use ($attributes): Visit {
            $year = (int) date('Y', strtotime((string) $attributes['visit_date']));
            $receiptNumber = $this->nextReceiptNumber($year);

            return Visit::create([...$attributes, 'receipt_number' => $receiptNumber]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Visit $visit, array $attributes): Visit
    {
        // REQ-CH-008 — receipt_number is immutable; any client-supplied value
        // on update is ignored, never applied.
        unset($attributes['receipt_number']);

        $visit->fill($attributes);
        $visit->save();

        return $visit->refresh();
    }

    private function nextReceiptNumber(int $year): string
    {
        $row = VisitReceiptSequence::where('year', $year)->lockForUpdate()->first();

        if (! $row) {
            $row = $this->createSequenceRow($year);
        }

        $nextNumber = (int) $row->getAttributes()['last_number'] + 1;
        $row->last_number = $nextNumber;
        $row->save();

        return sprintf('V-%d-%06d', $year, $nextNumber);
    }

    /**
     * Create the zero-count sequence row inside a nested transaction
     * (Postgres SAVEPOINT) so a concurrent winner's unique violation aborts
     * only the savepoint, not the caller's transaction; the caller then
     * re-reads the winning row under lock.
     */
    private function createSequenceRow(int $year): VisitReceiptSequence
    {
        try {
            DB::transaction(function () use ($year): void {
                VisitReceiptSequence::create(['year' => $year, 'last_number' => 0]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the row first — fall through to re-read it.
        }

        return VisitReceiptSequence::where('year', $year)->lockForUpdate()->firstOrFail();
    }
}
