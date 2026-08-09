<?php

namespace Modules\Collections\Services;

use Modules\Collections\Models\AmountMode;
use Modules\Collections\Models\ChargePolicy;
use Modules\Housing\Models\Unit;

/**
 * AmountCalculator computes the expected_amount in minor units (cents/centavos)
 * for a given ChargePolicy and Unit.
 *
 * ROUNDING DECISION (by_share):
 *  - Per-unit compute() uses PHP_ROUND_HALF_UP. Used for single-unit/ad-hoc charges; in
 *    isolation it cannot guarantee a zero-sum allocation.
 *  - For a WHOLE batch of by_share units, use computeByShareAllocation(): it allocates by
 *    floor and distributes the leftover centavos by LARGEST REMAINDER, so the per-unit
 *    amounts sum EXACTLY to prorate_total_amount (no drift) when the shares cover 100%.
 *
 * All inputs and outputs are in minor units (integer centavos). Never decimal.
 */
class AmountCalculator
{
    /**
     * Compute expected_amount in minor units for this policy/unit combination.
     *
     * @param  ChargePolicy  $policy  The policy whose amount configuration applies.
     * @param  Unit  $unit  The unit being charged.
     * @return int Minor units (centavos). Returns 0 for free/unresolvable modes.
     */
    public function compute(ChargePolicy $policy, Unit $unit): int
    {
        $modeId = $policy->amount_mode_id ?? AmountMode::FIXED_REQUIRED;

        return match ($modeId) {
            AmountMode::FIXED_REQUIRED,
            AmountMode::FIXED_OPTIONAL => $this->getRawInt($policy, 'fixed_amount'),

            AmountMode::RANGE => $this->getRawInt($policy, 'ideal_amount'),

            AmountMode::FREE => 0,

            AmountMode::BY_SHARE => $this->computeByShare($policy, $unit),

            default => $this->getRawInt($policy, 'fixed_amount'),
        };
    }

    /**
     * Prorate total amount across units by ownership_share percentage.
     * ownership_share is stored as a decimal (e.g. 1.3542 means 1.3542%).
     *
     * Formula: round(prorate_total_amount * (ownership_share / 100))
     *
     * Returns 0 when ownership_share is null or prorate_total_amount is unset.
     */
    private function computeByShare(ChargePolicy $policy, Unit $unit): int
    {
        $totalRaw = $this->getRawInt($policy, 'prorate_total_amount');
        $share = (float) $unit->ownership_share;

        if ($totalRaw === 0 || $share <= 0) {
            return 0;
        }

        return (int) round($totalRaw * ($share / 100.0), 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Allocate a by_share policy across a WHOLE set of units with ZERO drift, using the
     * LARGEST-REMAINDER (Hamilton) method: each unit gets the floor of its exact share, then
     * the leftover centavos go one-by-one to the units with the largest fractional remainder.
     *
     * The result sums EXACTLY to prorate_total_amount whenever the units' shares total 100%.
     * (If shares total < 100%, the allocated total equals floor(total * Σshare/100) + leftover
     *  distribution over the participating units — still exact for the prorated base.)
     *
     * @param  iterable<Unit>  $units
     * @return array<string, int> unit_id => minor units
     */
    public function computeByShareAllocation(ChargePolicy $policy, iterable $units): array
    {
        $totalRaw = $this->getRawInt($policy, 'prorate_total_amount');

        /** @var array<string, float> $exact */
        $exact = [];
        foreach ($units as $unit) {
            $share = (float) $unit->ownership_share;
            $exact[$unit->id] = $totalRaw > 0 && $share > 0
                ? $totalRaw * ($share / 100.0)
                : 0.0;
        }

        if ($totalRaw <= 0 || $exact === []) {
            return array_map(static fn (): int => 0, $exact);
        }

        // Floor each share; track the running floored sum and the fractional remainders.
        $allocation = [];
        $remainders = [];
        $flooredSum = 0;
        foreach ($exact as $unitId => $value) {
            $floor = (int) floor($value);
            $allocation[$unitId] = $floor;
            $remainders[$unitId] = $value - $floor;
            $flooredSum += $floor;
        }

        // Distribute the leftover centavos to the largest fractional remainders first.
        $target = (int) round(array_sum($exact));
        $leftover = $target - $flooredSum;

        if ($leftover > 0) {
            arsort($remainders); // largest remainder first; stable enough for v1
            foreach (array_keys($remainders) as $unitId) {
                if ($leftover <= 0) {
                    break;
                }
                $allocation[$unitId]++;
                $leftover--;
            }
        }

        return $allocation;
    }

    /**
     * Read the raw integer value of a money column (bypasses MoneyIntegerCast).
     * MoneyIntegerCast stores minor-unit integers; getAttributes() returns the raw int.
     */
    private function getRawInt(ChargePolicy $policy, string $column): int
    {
        return (int) ($policy->getAttributes()[$column] ?? 0);
    }
}
