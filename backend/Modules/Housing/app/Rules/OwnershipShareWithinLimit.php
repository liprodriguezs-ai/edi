<?php

namespace Modules\Housing\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Housing\Models\Unit;

/**
 * §6.3.3 — Validates that the SUM of ownership_share (indiviso) across the tenant's
 * live units does not exceed 100%, including the value being created/updated.
 *
 * On update, the unit being edited is excluded from the running total so its own old
 * value is not double-counted. Tenant isolation comes for free via Unit's TenantScope.
 */
class OwnershipShareWithinLimit implements ValidationRule
{
    public function __construct(private readonly ?string $ignoreUnitId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $query = Unit::query();

        if ($this->ignoreUnitId !== null) {
            $query->where('id', '!=', $this->ignoreUnitId);
        }

        $existingTotal = (float) $query->sum('ownership_share');
        $projectedTotal = $existingTotal + (float) $value;

        // Tolerance for binary float representation of decimal(7,4) sums.
        if ($projectedTotal > 100.0 + 0.00005) {
            $fail('La suma de indivisos del condominio no puede superar el 100%. Disponible: '
                .number_format(max(0, 100 - $existingTotal), 4).'%.');
        }
    }
}
