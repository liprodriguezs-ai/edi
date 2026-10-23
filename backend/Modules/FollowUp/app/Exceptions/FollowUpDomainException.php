<?php

declare(strict_types=1);

namespace Modules\FollowUp\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

/**
 * Self-rendering domain exception for every typed 4xx the FollowUp API
 * surfaces (design §4). Mirrors Modules\Screening\Exceptions\ScreeningDomainException.
 */
final class FollowUpDomainException extends Exception implements ShouldntReport
{
    /** @var array<string, mixed> */
    private array $payload;

    private int $status;

    private function __construct(string $message, string $code, int $status, array $context = [])
    {
        parent::__construct($message);

        $this->status = $status;
        $this->payload = ['message' => $message, 'code' => $code, ...$context];
    }

    public static function duplicate(): self
    {
        return new self(
            'Ya existe un seguimiento de este tipo para esta Prueba EDI.',
            'FOLLOW_UP_DUPLICATE',
            422,
        );
    }

    public static function frozen(): self
    {
        return new self(
            'Este seguimiento está cerrado y ya no admite cambios.',
            'FOLLOW_UP_FROZEN',
            409,
        );
    }

    public static function invalidStatusTransition(string $message): self
    {
        return new self($message, 'INVALID_STATUS_TRANSITION', 409);
    }

    public static function typeMismatch(): self
    {
        return new self(
            'Solo se puede crear una referencia sobre un seguimiento de tipo "Referencia a especialista".',
            'FOLLOW_UP_TYPE_MISMATCH',
            422,
        );
    }

    public static function screeningNotEligible(): self
    {
        return new self(
            'El niño no tiene una Prueba EDI completada y vigente para crear este seguimiento.',
            'SCREENING_NOT_ELIGIBLE',
            422,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json($this->payload, $this->status);
    }
}
