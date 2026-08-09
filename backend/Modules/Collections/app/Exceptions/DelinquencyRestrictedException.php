<?php

namespace Modules\Collections\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use QCH\Core\Support\TenantMoney;

/**
 * The audience-split 403 for a delinquency-restricted action (R-DELQ-6).
 *
 * The split lives in these two named constructors, not at call sites: the
 * guard-facing factory physically cannot carry an amount because it takes no
 * money argument, so a resident's debt can never leak into the package-intake
 * guard's response by a forgotten `unset()` at some call site.
 *
 * `forGuard()`'s message is a class constant — deliberately NOT configurable
 * (nobody can write "Unidad morosa" into it via settings) and contains no
 * amount, no notice text, and none of "adeudo"/"moroso"/"deuda".
 *
 * Implements ShouldntReport (sdd-verify CRITICAL): this exception is never
 * caught in production code — it relies entirely on self-render() — so
 * without this marker Laravel's handler would call report() on EVERY routine
 * "unit has overdue debt" 403, logging normal business behavior as an
 * application error and alerting Nightwatch on it. This is not an
 * HttpException (unlike ChatAccessDeniedException/CheckoutUnavailableException,
 * which sidestep the same problem via `abort(403, ...)` at each call site —
 * that pattern was rejected here because it would duplicate payload-building
 * across five call sites and destroy the type-level privacy guarantee
 * forGuard() gives by construction). ShouldntReport gets the same
 * "don't log this as an error" outcome without either cost.
 */
final class DelinquencyRestrictedException extends Exception implements ShouldntReport
{
    private const GUARD_MESSAGE = 'Esta unidad no puede recibir paquetes.';

    private const CODE = 'DELINQUENT_RESTRICTED';

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    public static function forResident(int $overdueBalanceMinor, string $noticeText): self
    {
        $exception = new self('Función restringida por adeudos vencidos.');

        $exception->payload = [
            'message' => $exception->getMessage(),
            'code' => self::CODE,
            'overdue_balance' => TenantMoney::make($overdueBalanceMinor),
            'notice_text' => $noticeText,
        ];

        return $exception;
    }

    public static function forGuard(): self
    {
        $exception = new self(self::GUARD_MESSAGE);

        $exception->payload = [
            'message' => self::GUARD_MESSAGE,
            'code' => self::CODE,
        ];

        return $exception;
    }

    public function render(): JsonResponse
    {
        return response()->json($this->payload, 403);
    }
}
