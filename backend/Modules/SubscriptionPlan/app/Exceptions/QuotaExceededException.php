<?php

declare(strict_types=1);

namespace Modules\SubscriptionPlan\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Modules\SubscriptionPlan\Services\QuotaCheck;

/**
 * Self-rendering 422 for the trial/active usage-quota guard (QuotaService).
 * Modeled directly on UnitLimitExceededException: a single named constructor
 * builds the full payload (message + numbers) so it never needs to be
 * duplicated across HTTP surfaces, and render() returns the response itself
 * instead of relying on a handler.
 *
 * Implements ShouldntReport (MANDATORY, REQ-QE-03): a quota block is an
 * expected business outcome — a tenant reached its plan's usage cap — not an
 * application error. Without this marker Laravel's exception handler would
 * call report() on EVERY blocked request, logging normal business behavior
 * as an error and alerting Nightwatch on it.
 *
 * 422 (not 403): the request is well-formed but unprocessable given the
 * tenant's current resource state — matches UnitLimitExceededException and
 * the delinquency-restriction guard on these same choke points. This is not
 * an actor-authorization failure.
 */
final class QuotaExceededException extends Exception implements ShouldntReport
{
    private const CODE = 'QUOTA_EXCEEDED';

    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    public static function forQuota(QuotaCheck $check): self
    {
        $message = self::buildMessage($check);

        $exception = new self($message);

        $exception->payload = [
            'message' => $message,
            'code' => self::CODE,
            'resource' => $check->resource,
            'limit' => $check->limit,
            'used' => $check->used,
            'scope' => $check->scope,
            'plan_name' => $check->planName,
        ];

        return $exception;
    }

    public function render(): JsonResponse
    {
        return response()->json($this->payload, 422);
    }

    /**
     * Mexican Spanish (tú-form), naming the concrete resource and limit
     * (REQ-QE-03).
     */
    private static function buildMessage(QuotaCheck $check): string
    {
        return sprintf(
            'Alcanzaste el límite de %d para %s de tu plan%s. Actualiza tu plan para continuar.',
            $check->limit,
            self::resourceLabel($check->resource),
            $check->planName !== null ? " ({$check->planName})" : '',
        );
    }

    private static function resourceLabel(string $resource): string
    {
        return match ($resource) {
            'unit' => 'unidades',
            'amenity_reservation' => 'reservaciones de amenidades',
            'visitor' => 'registros de visitantes',
            default => $resource,
        };
    }
}
