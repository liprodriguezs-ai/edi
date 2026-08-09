<?php

namespace Modules\Access\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Housing\Models\UnitUser;

/**
 * §14.2 "Generar QR para MI visita": non-staff callers may only target units they
 * actively belong to (unit_user without end_date). Staff — full-log readers
 * (security/treasurer/president/admin) — may target any unit of the tenant.
 *
 * Lives in rules() (not withValidator) because Orion's DelegatingModuleRequest
 * delegates rules()/messages()/attributes() only.
 */
class CallerBelongsToUnit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user || ! is_string($value)) {
            return;
        }

        if ($user->tokenCan('*') || $user->tokenCan('read.access_log')) {
            return;
        }

        $isMember = UnitUser::where('unit_id', $value)
            ->where('user_id', $user->id)
            ->whereNull('end_date')
            ->exists();

        if (! $isMember) {
            $fail('Solo puedes generar códigos QR para tu propia unidad.');
        }
    }
}
