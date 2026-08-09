<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Modules\SubscriptionPlan\Services\UnitLimitCheck;

/**
 * Self-rendering 422 for a unit-band guard, blocking one of TWO distinct
 * business events:
 *
 * - forIncrease(): a unit-count INCREASE blocked by the tenant's CURRENT
 *   billing-band cap (POST /unit, /unit/batch, /unit/{id}/restore,
 *   /unit/batch/restore, and the importer's preview).
 * - forPlanChange(): a self-serve PLAN CHANGE blocked because the tenant's
 *   current active unit count doesn't fit the TARGET plan's band
 *   (ConfigurationController::changeBillingPlan — plan-downgrade-guard).
 *
 * Modeled on the template's domain-restriction exception:
 * a single named constructor per business event builds the full payload
 * (message + numbers) so it never needs to be duplicated across HTTP
 * surfaces, and `render()` returns the response itself instead of relying on
 * a handler.
 *
 * Implements ShouldntReport (MANDATORY): both events are routine business
 * blocks — a tenant hit its plan's unit cap — not application errors.
 * Without this marker Laravel's exception handler would call report() on
 * EVERY blocked request, logging normal business behavior as an error and
 * alerting Nightwatch on it.
 *
 * 422 (not 403): the request is well-formed but unprocessable given the
 * tenant's current resource state — matches the sibling guards already on
 * these same endpoints (unit identity uniqueness, beforeDestroy, import
 * validate). This is not an actor-authorization failure.
 */
final class UnitLimitExceededException extends Exception implements ShouldntReport
{
    private const CODE = 'UNIT_LIMIT_EXCEEDED';

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    public static function forIncrease(UnitLimitCheck $check): self
    {
        $message = self::buildMessage($check);

        $exception = new self($message);

        $exception->payload = [
            'message' => $message,
            'code' => self::CODE,
            'plan_name' => $check->planName,
            'unit_limit' => $check->unitLimit,
            'active_units' => $check->activeUnits,
            'attempted_increase' => $check->attemptedIncrease,
            'suggested_plan_name' => $check->suggestedPlanName,
        ];

        return $exception;
    }

    /**
     * The plan-downgrade-guard variant (REQ-01/REQ-08): $message is built by
     * the caller via UnitLimitService::buildPlanChangeMessage() (TARGET
     * semantics), never buildMessage() below (INCREASE semantics — its
     * "No es posible agregar 0 más" copy would be nonsensical here).
     * `attempted_increase` is DELIBERATELY omitted from this payload — it is
     * always 0 under target semantics and would be a meaningless key.
     */
    public static function forPlanChange(UnitLimitCheck $check, string $message): self
    {
        $exception = new self($message);

        $exception->payload = [
            'message' => $message,
            'code' => self::CODE,
            'plan_name' => $check->planName,
            'unit_limit' => $check->unitLimit,
            'active_units' => $check->activeUnits,
            'suggested_plan_name' => $check->suggestedPlanName,
        ];

        return $exception;
    }

    public function render(): JsonResponse
    {
        return response()->json($this->payload, 422);
    }

    /**
     * Mexican Spanish (tú-form), naming all four concrete numbers (REQ-09).
     * Degrades gracefully at the top band — no dead-end upgrade link (REQ-08)
     * when `suggestedPlanName` is null.
     */
    private static function buildMessage(UnitLimitCheck $check): string
    {
        $base = sprintf(
            'Tu plan %s permite hasta %d unidades activas y ya tienes %d. No es posible agregar %d más.',
            $check->planName,
            $check->unitLimit,
            $check->activeUnits,
            $check->attemptedIncrease,
        );

        if ($check->suggestedPlanName !== null) {
            return $base.' Considera cambiar al plan '.$check->suggestedPlanName.'.';
        }

        return $base.' Contacta a soporte para ampliar tu límite.';
    }
}
