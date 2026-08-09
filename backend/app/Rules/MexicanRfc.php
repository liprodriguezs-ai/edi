<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * §18.7 — Mexican RFC (Registro Federal de Contribuyentes) format validation.
 *
 * Accepts both persona física (13 chars: 4 letters) and persona moral (12 chars:
 * 3 letters) RFCs:
 *   - 3 or 4 initial letters/Ñ/& (entity name)
 *   - 6 digits (date YYMMDD)
 *   - 3 alphanumeric homoclave characters
 *
 * Validation is format-only (case-insensitive). It does NOT verify the check digit.
 */
class MexicanRfc implements ValidationRule
{
    private const PATTERN = '/^([A-ZÑ&]{3,4})\d{6}([A-Z\d]{3})$/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! preg_match(self::PATTERN, trim($value))) {
            $fail('El :attribute no tiene un formato de RFC válido.');
        }
    }
}
