<?php

declare(strict_types=1);

namespace Modules\Screening\Services;

use DateTimeImmutable;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Screening\Services\Scoring\AgeResolver;
use Modules\Screening\Services\Scoring\Values\AgeGroupDefinition;
use Modules\Screening\Services\Scoring\Values\RuleSet;

/**
 * The standalone "Edad corregida" calculator (odd/tasks/edi-legacy-parity.md
 * S6). It never owns the prematurity rule: ages come from the same
 * {@see AgeResolver} and the same default published version the Prueba EDI
 * uses, so the calculator and an applied test can never disagree.
 */
final class CorrectedAgeCalculator
{
    public const REASON_TERM_BIRTH = 'term_birth';

    public const REASON_OVER_TWO_YEARS = 'over_two_years';

    /** Display convention of the EDI tables (and AutoAnswerDeriver): a month is 30 days. */
    private const DAYS_PER_MONTH = 30;

    /** The Prueba EDI starts at 1 month (Grupo 1), corrected age included. */
    private const MIN_TEST_AGE_DAYS = 30;

    public function __construct(
        private readonly RuleSetProvider $ruleSetProvider,
        private readonly AgeResolver $ageResolver = new AgeResolver,
    ) {}

    /**
     * @return array{
     *     birth_date: string,
     *     reference_date: string,
     *     gestational_weeks: int,
     *     applies: bool,
     *     reason: ?string,
     *     chronological: array{days: int, months: int, remaining_days: int},
     *     corrected: array{days: int, months: int, remaining_days: int}|null,
     *     chronological_age_group: array<string, mixed>|null,
     *     corrected_age_group: array<string, mixed>|null,
     *     ready_from: ?string,
     * }
     */
    public function calculate(string $birthDate, string $referenceDate, int $gestationalWeeks): array
    {
        $version = InstrumentVersion::query()
            ->where('is_default', true)
            ->whereNotNull('published_at')
            ->firstOrFail();
        $ruleSet = $this->ruleSetProvider->for($version->key);

        $ages = $this->ageResolver->resolveAges(
            new DateTimeImmutable($birthDate),
            new DateTimeImmutable($referenceDate),
            $ruleSet->ageCalculationMode,
            $gestationalWeeks,
        );

        $chronologicalDays = $ages['chronological_age_days'];
        $correctedDays = $ages['corrected_age_days'];

        return [
            'birth_date' => $birthDate,
            'reference_date' => $referenceDate,
            'gestational_weeks' => $gestationalWeeks,
            'applies' => $correctedDays !== null,
            'reason' => $correctedDays !== null ? null : $this->notApplicableReason($gestationalWeeks),
            'chronological' => $this->breakdown($chronologicalDays),
            'corrected' => $correctedDays !== null ? $this->breakdown($correctedDays) : null,
            'chronological_age_group' => $this->groupFor($ruleSet, $version, $chronologicalDays),
            'corrected_age_group' => $correctedDays !== null ? $this->groupFor($ruleSet, $version, $correctedDays) : null,
            // A corrected age under 1 month means the test waits: the date it can be applied.
            'ready_from' => $correctedDays !== null && $correctedDays < self::MIN_TEST_AGE_DAYS
                ? (new DateTimeImmutable($referenceDate))->modify('+'.(self::MIN_TEST_AGE_DAYS - $correctedDays).' days')->format('Y-m-d')
                : null,
        ];
    }

    /**
     * Only labels WHY AgeResolver returned no corrected age; the decision itself
     * is AgeResolver's. Its gates: < 37 weeks and under 2 years -- so the
     * first unmet one names the reason.
     */
    private function notApplicableReason(int $gestationalWeeks): string
    {
        if ($gestationalWeeks >= AgeResolver::PREMATURITY_GESTATIONAL_WEEKS_GATE) {
            return self::REASON_TERM_BIRTH;
        }

        return self::REASON_OVER_TWO_YEARS;
    }

    /**
     * @return array{days: int, months: int, remaining_days: int}
     */
    private function breakdown(int $days): array
    {
        // A negative corrected age (still before the due date) has no months:
        // splitting it gave "-1 meses -22 días".
        if ($days < 0) {
            return ['days' => $days, 'months' => 0, 'remaining_days' => $days];
        }

        return [
            'days' => $days,
            'months' => intdiv($days, self::DAYS_PER_MONTH),
            'remaining_days' => $days % self::DAYS_PER_MONTH,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function groupFor(RuleSet $ruleSet, InstrumentVersion $version, int $days): ?array
    {
        $definition = array_find(
            $ruleSet->officialGroups(),
            fn (AgeGroupDefinition $group): bool => $group->containsDay($days),
        );

        if ($definition === null) {
            return null;
        }

        return AgeGroup::query()
            ->where('instrument_version_id', $version->id)
            ->where('code', $definition->code)
            ->first(AgeGroupCatalog::COLUMNS)
            ?->toArray();
    }
}
