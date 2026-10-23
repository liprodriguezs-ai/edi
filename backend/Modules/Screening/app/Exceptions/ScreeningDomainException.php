<?php

declare(strict_types=1);

namespace Modules\Screening\Exceptions;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;

/**
 * Self-rendering domain exception for every typed 4xx the screening API
 * surfaces (design §4). One named static constructor per error code builds
 * the full Spanish-message + code + context payload; render() returns the
 * response itself. Implements ShouldntReport: every one of these is an
 * expected business-flow outcome (a gate, a freeze, a mismatch), never an
 * application error.
 */
final class ScreeningDomainException extends Exception implements ShouldntReport
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

    public static function screeningNotApplicable(string $message): self
    {
        return new self($message, 'SCREENING_NOT_APPLICABLE', 422);
    }

    public static function visitAlreadyScreened(): self
    {
        return new self(
            'Esta visita ya tiene una Prueba EDI registrada.',
            'VISIT_ALREADY_SCREENED',
            422,
        );
    }

    /**
     * @param  array<int, string>  $missingItemIds
     */
    public static function incompleteAnswers(array $missingItemIds): self
    {
        return new self(
            'Faltan reactivos propios del grupo por contestar.',
            'INCOMPLETE_ANSWERS',
            422,
            ['missing_item_ids' => $missingItemIds],
        );
    }

    /**
     * @param  array<int, array{development_area_id: string, code: string, missing_items: array<int, array<string, mixed>>}>  $areas
     */
    public static function alternateItemsRequired(array $areas): self
    {
        return new self(
            'Uno o más ejes salieron todo amarillo: se requiere contestar la evaluación alterna correspondiente.',
            'ALTERNATE_ITEMS_REQUIRED',
            422,
            ['areas' => $areas],
        );
    }

    public static function screeningFrozen(): self
    {
        return new self(
            'Esta Prueba EDI está congelada y ya no admite cambios.',
            'SCREENING_FROZEN',
            409,
        );
    }

    public static function invalidStatusTransition(string $message): self
    {
        return new self($message, 'INVALID_STATUS_TRANSITION', 409);
    }

    public static function versionPinned(): self
    {
        return new self(
            'Un recálculo autoritativo siempre usa la versión del instrumento con la que se aplicó la Prueba EDI.',
            'VERSION_PINNED',
            422,
        );
    }

    public static function rulesFingerprintMismatch(): self
    {
        return new self(
            'Las reglas de esta versión del instrumento cambiaron desde que se calificó esta Prueba EDI.',
            'RULES_FINGERPRINT_MISMATCH',
            409,
        );
    }

    public static function confirmatoryNotApplicable(): self
    {
        return new self(
            'Solo se puede registrar una prueba confirmatoria para una Prueba EDI completada con resultado rojo.',
            'CONFIRMATORY_NOT_APPLICABLE',
            422,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json($this->payload, $this->status);
    }
}
