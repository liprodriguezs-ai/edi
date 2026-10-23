<?php

declare(strict_types=1);

namespace Modules\Appointment\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

/**
 * Self-rendering domain exception for every typed 4xx the Appointment API
 * surfaces. Mirrors Modules\FollowUp\Exceptions\FollowUpDomainException.
 */
final class AppointmentDomainException extends Exception implements ShouldntReport
{
    /** @var array<string, mixed> */
    private array $payload;

    private int $status;

    private function __construct(string $message, string $code, int $status)
    {
        parent::__construct($message);

        $this->status = $status;
        $this->payload = ['message' => $message, 'code' => $code];
    }

    public static function frozen(): self
    {
        return new self(
            'Esta cita está cerrada y ya no admite cambios.',
            'APPOINTMENT_FROZEN',
            409,
        );
    }

    public static function invalidStatusTransition(string $message): self
    {
        return new self($message, 'INVALID_STATUS_TRANSITION', 409);
    }

    public static function followUpMismatch(): self
    {
        return new self(
            'El seguimiento indicado no pertenece a este niño.',
            'FOLLOW_UP_MISMATCH',
            422,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json($this->payload, $this->status);
    }
}
