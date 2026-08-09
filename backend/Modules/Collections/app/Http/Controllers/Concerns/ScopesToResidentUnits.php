<?php

namespace Modules\Collections\Http\Controllers\Concerns;

use Modules\Housing\Models\UnitUser;

/**
 * Resident read-scoping for Collections resources (spec §8.2 "Ver estado de cuenta
 * de otros: Resi ❌" — finding collections-02).
 *
 * Residents/renters hold read.charge / read.payment_application / read.credit_balance
 * so their app can list THEIR OWN finances, but they must never enumerate the whole
 * cluster's ledger. The broad `read.payment` ability marks board/administration access
 * (same marker the statement endpoint uses); callers without it are constrained to the
 * units they belong to via the unit_user pivot. A foreign show resolves to 404.
 */
trait ScopesToResidentUnits
{
    /**
     * Whether the current caller lacks board-wide read access and must be
     * restricted to their own units.
     */
    protected function lacksBoardReadAccess(): bool
    {
        $user = auth()->guard('sanctum')->user();

        return ! ($user && ($user->tokenCan('read.payment') || $user->tokenCan('*')));
    }

    /**
     * Unit ids the authenticated user belongs to (tenant-scoped via TenantScope).
     *
     * @return array<int, string>
     */
    protected function ownUnitIds(): array
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user) {
            return [];
        }

        return UnitUser::where('user_id', $user->id)
            ->whereNull('end_date')
            ->pluck('unit_id')
            ->unique()
            ->values()
            ->all();
    }
}
