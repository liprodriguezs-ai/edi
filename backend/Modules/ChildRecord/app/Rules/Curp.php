<?php

namespace Modules\ChildRecord\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * REQ-CH-002 — official CURP pattern validation, format only.
 *
 * D-16 (design.md §8) overrides the original assumption: a mismatch between
 * the CURP's embedded birth-date/sex segment and the captured
 * birth_date/sex_id MUST NOT block the registration — it is a WARNING
 * surfaced via `curp_mismatch`, never a validation failure. This rule
 * therefore only enforces the 18-character official pattern (hard 422).
 * Cross-consistency is computed separately by `isConsistentWith()`, used by
 * the controller/service to set the non-blocking flag.
 */
class Curp implements ValidationRule
{
    public const PATTERN = '/^[A-Z][AEIOUX][A-Z]{2}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])[HM](AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-Z\d]\d$/';

    /**
     * Format check is case-insensitive by design: Orion's request pipeline in
     * this project (QCH\Core\Http\Request\DelegatingModuleRequest) validates
     * BEFORE the controller hook runs, so `prepareForValidation()` never fires
     * for store/update actions — normalization to uppercase for STORAGE
     * happens in the controller instead (ChildController). This rule accepts
     * any case at the format-check level so a lowercase-but-otherwise-valid
     * CURP is not rejected before it ever reaches that normalization step.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! preg_match(self::PATTERN, strtoupper($value))) {
            $fail('El :attribute no tiene un formato de CURP válido.');
        }
    }

    /**
     * Whether the CURP's embedded birth-date (positions 5-10, YYMMDD) and sex
     * character (position 11, H/M) agree with the given birth_date and
     * sex key ('male'/'female'). Returns true when it cannot determine
     * (malformed CURP, unknown sex key) — callers should only invoke this
     * after format validation already passed.
     */
    public static function isConsistentWith(string $curp, string $birthDate, string $sexKey): bool
    {
        $curp = strtoupper($curp);

        if (! preg_match(self::PATTERN, $curp)) {
            return true;
        }

        $curpDateSegment = substr($curp, 4, 6);
        $expectedDateSegment = date('ymd', strtotime($birthDate));

        if ($curpDateSegment !== $expectedDateSegment) {
            return false;
        }

        $curpSexChar = $curp[10];
        $expectedSexChar = match ($sexKey) {
            'male' => 'H',
            'female' => 'M',
            default => null,
        };

        if ($expectedSexChar === null) {
            return true;
        }

        return $curpSexChar === $expectedSexChar;
    }
}
