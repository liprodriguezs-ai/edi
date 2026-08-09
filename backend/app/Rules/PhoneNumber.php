<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * §18.7 — Lightweight E.164-ish phone validation.
 *
 * Accepts an optional leading "+", then 8–15 digits, ignoring spaces, dashes and
 * parentheses which are common formatting separators. This validates SHAPE only; it
 * does not verify the number is reachable or normalize it.
 */
class PhoneNumber implements ValidationRule
{
    private const PATTERN = '/^\+?\d{8,15}$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $normalized = is_string($value)
            ? preg_replace('/[\s\-()]/', '', $value)
            : '';

        if (! preg_match(self::PATTERN, (string) $normalized)) {
            $fail('El :attribute no tiene un formato de teléfono válido.');
        }
    }
}
