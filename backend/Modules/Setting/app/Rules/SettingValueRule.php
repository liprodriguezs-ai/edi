<?php

namespace Modules\Setting\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Setting\Models\Setting;

/**
 * Strict `value` shape validator for the `/setting` Orion resource, enforcing
 * exactly the design D2 acceptance table (boolean→bool, integer→int,
 * json→array, string→string). Deliberately does NOT use Laravel's built-in
 * `boolean`/`integer` rules — those accept coercible strings ('1', 'true',
 * '15') and would let a pre-serialized payload reach the model's `saving`
 * guard, turning what should be a 422 validation error into a 500.
 *
 * `value` stays `nullable` at the FormRequest level, so this rule is never
 * invoked for a null value — no null branch needed here.
 */
class SettingValueRule implements ValidationRule
{
    public function __construct(private readonly string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isValid = match ($this->type) {
            Setting::TYPE_BOOLEAN => is_bool($value),
            Setting::TYPE_INTEGER => is_int($value),
            Setting::TYPE_JSON => is_array($value),
            Setting::TYPE_STRING => is_string($value),
            default => is_string($value),
        };

        if (! $isValid) {
            $fail("El valor de :attribute no corresponde al tipo '{$this->type}' declarado.");
        }
    }
}
