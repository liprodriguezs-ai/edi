<?php

namespace Modules\Screening\Services\Scoring\Values;

use DateTimeImmutable;
use Modules\Screening\Services\Scoring\Exceptions\InvalidScoringInputException;

/**
 * Everything `ScoringEngine::score()` needs, and nothing it computes itself
 * (design D5). Two named constructors, mutually exclusive:
 *
 * - {@see self::forGroup()}: the applied official group is given explicitly.
 *   Used for oracle replay and for recomputing a STORED screening, which
 *   must reuse the group it was originally scored under, never re-resolve
 *   dates against today's RuleSet.
 * - {@see self::forDates()}: birth/test dates are given and `AgeResolver` +
 *   `AgeGroupResolver` determine the applied group. The normal application
 *   path.
 *
 * All perinatal fields are `?int` -- NULL means "se ignora" (design D8),
 * never "no"/zero.
 */
final readonly class ScoringInput
{
    /**
     * @param  array<int, ItemAnswer>  $answers
     * @param  array<string, bool|null>  $riskFactorAnswers  FRB code => yes/no/unknown
     */
    private function __construct(
        public ?string $ageGroupCode,
        public ?DateTimeImmutable $birthDate,
        public ?DateTimeImmutable $testDate,
        public ?string $sexKey,
        public ?int $gestationalAgeWeeks,
        public ?int $birthWeightGrams,
        public ?int $motherAgeAtBirthYears,
        public ?int $headCircumferenceMm,
        public array $answers,
        public array $riskFactorAnswers,
        public ?string $previousResultColorKey,
    ) {
        $hasGroup = $this->ageGroupCode !== null;
        $hasDates = $this->birthDate !== null && $this->testDate !== null;

        if ($hasGroup === $hasDates) {
            throw new InvalidScoringInputException(
                'ScoringInput requires EXACTLY ONE of an explicit age_group_code or a (birth_date, test_date) pair.'
            );
        }
    }

    /**
     * @param  array<int, ItemAnswer>  $answers
     * @param  array<string, bool|null>  $riskFactorAnswers
     */
    public static function forGroup(
        string $ageGroupCode,
        array $answers = [],
        array $riskFactorAnswers = [],
        ?string $sexKey = null,
        ?int $gestationalAgeWeeks = null,
        ?int $birthWeightGrams = null,
        ?int $motherAgeAtBirthYears = null,
        ?int $headCircumferenceMm = null,
        ?string $previousResultColorKey = null,
    ): self {
        return new self(
            ageGroupCode: $ageGroupCode,
            birthDate: null,
            testDate: null,
            sexKey: $sexKey,
            gestationalAgeWeeks: $gestationalAgeWeeks,
            birthWeightGrams: $birthWeightGrams,
            motherAgeAtBirthYears: $motherAgeAtBirthYears,
            headCircumferenceMm: $headCircumferenceMm,
            answers: $answers,
            riskFactorAnswers: $riskFactorAnswers,
            previousResultColorKey: $previousResultColorKey,
        );
    }

    /**
     * @param  array<int, ItemAnswer>  $answers
     * @param  array<string, bool|null>  $riskFactorAnswers
     */
    public static function forDates(
        DateTimeImmutable $birthDate,
        DateTimeImmutable $testDate,
        array $answers = [],
        array $riskFactorAnswers = [],
        ?string $sexKey = null,
        ?int $gestationalAgeWeeks = null,
        ?int $birthWeightGrams = null,
        ?int $motherAgeAtBirthYears = null,
        ?int $headCircumferenceMm = null,
        ?string $previousResultColorKey = null,
    ): self {
        return new self(
            ageGroupCode: null,
            birthDate: $birthDate,
            testDate: $testDate,
            sexKey: $sexKey,
            gestationalAgeWeeks: $gestationalAgeWeeks,
            birthWeightGrams: $birthWeightGrams,
            motherAgeAtBirthYears: $motherAgeAtBirthYears,
            headCircumferenceMm: $headCircumferenceMm,
            answers: $answers,
            riskFactorAnswers: $riskFactorAnswers,
            previousResultColorKey: $previousResultColorKey,
        );
    }
}
