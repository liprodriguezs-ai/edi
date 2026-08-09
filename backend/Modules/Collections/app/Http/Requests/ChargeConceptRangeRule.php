<?php

namespace Modules\Collections\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Request;
use Modules\Collections\Models\AmountMode;

/**
 * Shared cross-field coherence check for the "Rango" amount mode (BUG-R9-1).
 *
 * When a charge concept is created/updated with amount_mode_id == RANGE, the
 * range must satisfy min_amount ≤ ideal_amount ≤ max_amount. Centralized here so
 * Create and Update requests stay in sync (active DRY). Messages are in Spanish.
 *
 * Only runs when the request explicitly carries amount_mode_id == RANGE — on a
 * partial update that doesn't touch the mode there is nothing new to validate.
 */
final class ChargeConceptRangeRule
{
    public static function validate(Request $request, Validator $validator): void
    {
        if ($request->input('amount_mode_id') !== AmountMode::RANGE) {
            return;
        }

        $min = self::intOrNull($request->input('min_amount'));
        $ideal = self::intOrNull($request->input('ideal_amount'));
        $max = self::intOrNull($request->input('max_amount'));

        // min ≤ ideal (both present and already validated as positive integers).
        if ($min !== null && $ideal !== null && $min > $ideal) {
            $validator->errors()->add(
                'min_amount',
                'El monto mínimo no puede ser mayor que el monto ideal.',
            );
        }

        // ideal ≤ max (max is optional; only check when provided).
        if ($ideal !== null && $max !== null && $ideal > $max) {
            $validator->errors()->add(
                'ideal_amount',
                'El monto ideal no puede ser mayor que el monto máximo.',
            );
        }

        // min ≤ max guards the case where ideal is absent but both bounds exist.
        if ($min !== null && $max !== null && $min > $max) {
            $validator->errors()->add(
                'max_amount',
                'El monto máximo no puede ser menor que el monto mínimo.',
            );
        }
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
