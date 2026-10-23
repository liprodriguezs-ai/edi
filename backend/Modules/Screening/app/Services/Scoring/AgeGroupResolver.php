<?php

namespace Modules\Screening\Services\Scoring;

use Modules\Screening\Services\Scoring\Exceptions\ScreeningNotApplicableException;
use Modules\Screening\Services\Scoring\Values\AgeGroupDefinition;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScoringFinding;

/**
 * Selects the official group whose day range contains the chronological
 * age; substitutes the corrected-age group when it exists and differs
 * (design D7 / REQ-SCO-003). A chronological age outside [30, 2159] days,
 * or a corrected age below 30 days (open decision Q3), refuses loudly.
 */
final class AgeGroupResolver
{
    private const MIN_CHRONOLOGICAL_AGE_DAYS = 30;

    private const MAX_CHRONOLOGICAL_AGE_DAYS = 2159;

    /**
     * @return array{applied_group_code: string, substituted: bool, findings: array<int, ScoringFinding>}
     */
    public function resolveGroup(RuleSet $ruleSet, int $chronologicalAgeDays, ?int $correctedAgeDays): array
    {
        if ($chronologicalAgeDays < self::MIN_CHRONOLOGICAL_AGE_DAYS || $chronologicalAgeDays > self::MAX_CHRONOLOGICAL_AGE_DAYS) {
            throw new ScreeningNotApplicableException(
                "La prueba EDI aplica de 1 mes a 5 años 11 meses de edad. La edad calculada es de {$chronologicalAgeDays} días; revisa la fecha de nacimiento y la fecha de la visita."
            );
        }

        $chronologicalGroup = $this->findOfficialGroup($ruleSet, $chronologicalAgeDays);

        if ($correctedAgeDays === null) {
            return ['applied_group_code' => $chronologicalGroup->code, 'substituted' => false, 'findings' => []];
        }

        if ($correctedAgeDays < self::MIN_CHRONOLOGICAL_AGE_DAYS) {
            throw new ScreeningNotApplicableException(
                "La edad corregida por prematurez es de {$correctedAgeDays} días. Aplica la Prueba EDI cuando su edad corregida cumpla 1 mes (30 días)."
            );
        }

        $correctedGroup = $this->findOfficialGroup($ruleSet, $correctedAgeDays);

        if ($correctedGroup->code === $chronologicalGroup->code) {
            return ['applied_group_code' => $chronologicalGroup->code, 'substituted' => false, 'findings' => []];
        }

        $finding = new ScoringFinding(ScoringFinding::AGE_GROUP_SUBSTITUTED_BY_CORRECTED_AGE, [
            'chronological_group_code' => $chronologicalGroup->code,
            'corrected_group_code' => $correctedGroup->code,
        ]);

        return ['applied_group_code' => $correctedGroup->code, 'substituted' => true, 'findings' => [$finding]];
    }

    private function findOfficialGroup(RuleSet $ruleSet, int $chronologicalOrCorrectedDays): AgeGroupDefinition
    {
        foreach ($ruleSet->officialGroups() as $group) {
            if ($group->containsDay($chronologicalOrCorrectedDays)) {
                return $group;
            }
        }

        throw new ScreeningNotApplicableException("Ningún grupo de edad de la prueba cubre {$chronologicalOrCorrectedDays} días.");
    }
}
