<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit;

use Modules\Screening\Services\Scoring\AutoAnswerDeriver;
use Modules\Screening\Services\Scoring\Values\DerivedAnswer;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScoringFinding;
use Modules\Screening\Services\Scoring\Values\ScoringInput;
use Modules\Screening\Tests\Unit\Support\RuleSetBuilder;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SCO-004 / design D8/D9/D10.
 */
final class AutoAnswerDeriverTest extends TestCase
{
    private AutoAnswerDeriver $deriver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deriver = new AutoAnswerDeriver;
    }

    private function ruleSet(): RuleSet
    {
        $builder = new RuleSetBuilder;
        $builder->official('G1', 30, 59);
        $builder->riskFactor('FRB3', 'gestational_age_lt_34_weeks');
        $builder->riskFactor('FRB4', 'birth_weight_lte_1500_g');
        $builder->riskFactor('FRB7', 'mother_age_lt_16_years');
        $builder->item('G1', 'EN1.3', 'EN', ['SI' => ResultColorKey::RED, 'NO' => ResultColorKey::GREEN], 3);
        $builder->areaRule('G1', 'EN', 1, 1, 0, false);
        $builder->headCircumferenceItem('G1', 'EN1.3');
        $builder->headCircumferenceBand('male', 1, 355, 390);
        $builder->withCatchAll('G1');

        return $builder->build();
    }

    public function test_threshold_edges_are_inclusive_only_for_birth_weight(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            gestationalAgeWeeks: 34,
            birthWeightGrams: 1500,
            motherAgeAtBirthYears: 16,
        );

        $result = $this->deriver->derive($input, $this->ruleSet(), 'G1', null);

        $this->assertFalse($result['risk_factor_answers']['FRB3']); // 34 < 34 is false
        $this->assertTrue($result['risk_factor_answers']['FRB4']); // 1500 <= 1500 is true
        $this->assertFalse($result['risk_factor_answers']['FRB7']); // 16 < 16 is false
    }

    public function test_below_threshold_derives_yes_for_all_three(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            gestationalAgeWeeks: 33,
            birthWeightGrams: 1499,
            motherAgeAtBirthYears: 15,
        );

        $result = $this->deriver->derive($input, $this->ruleSet(), 'G1', null);

        $this->assertTrue($result['risk_factor_answers']['FRB3']);
        $this->assertTrue($result['risk_factor_answers']['FRB4']);
        $this->assertTrue($result['risk_factor_answers']['FRB7']);
    }

    public function test_null_input_derives_nothing_and_records_a_finding(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            birthWeightGrams: null,
            riskFactorAnswers: ['FRB4' => true],
        );

        $result = $this->deriver->derive($input, $this->ruleSet(), 'G1', null);

        // manual answer stands -- NULL is never treated as "no".
        $this->assertTrue($result['risk_factor_answers']['FRB4']);

        $unknownFindings = array_values(array_filter(
            $result['findings'],
            fn (ScoringFinding $f) => $f->key === ScoringFinding::UNKNOWN_PERINATAL_INPUT && ($f->context['risk_factor_code'] ?? null) === 'FRB4'
        ));
        $this->assertCount(1, $unknownFindings);
    }

    public function test_known_value_overrides_a_differing_manual_answer_with_a_finding(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            birthWeightGrams: 1500, // derives TRUE
            riskFactorAnswers: ['FRB4' => false], // manual says NO
        );

        $result = $this->deriver->derive($input, $this->ruleSet(), 'G1', null);

        $this->assertTrue($result['risk_factor_answers']['FRB4']);

        $overrideFindings = array_values(array_filter(
            $result['findings'],
            fn (ScoringFinding $f) => $f->key === ScoringFinding::AUTO_ANSWER_OVERRODE_MANUAL && ($f->context['risk_factor_code'] ?? null) === 'FRB4'
        ));
        $this->assertCount(1, $overrideFindings);
        $this->assertFalse($overrideFindings[0]->context['manual_value']);
        $this->assertTrue($overrideFindings[0]->context['derived_value']);
    }

    public function test_matching_manual_answer_overrides_silently_without_a_finding(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            birthWeightGrams: 1500, // derives TRUE
            riskFactorAnswers: ['FRB4' => true], // manual already agrees
        );

        $result = $this->deriver->derive($input, $this->ruleSet(), 'G1', null);

        $overrideFindings = array_filter($result['findings'], fn (ScoringFinding $f) => $f->key === ScoringFinding::AUTO_ANSWER_OVERRODE_MANUAL);
        $this->assertSame([], array_values($overrideFindings));
    }

    public function test_head_circumference_in_band_selects_the_green_option(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            sexKey: 'male',
            headCircumferenceMm: 360,
        );

        // chronological age 30 days -> age_months = intdiv(30, 30) = 1.
        $result = $this->deriver->derive($input, $this->ruleSet(), 'G1', 30);

        $this->assertNotNull($result['head_circumference_answer']);
        $this->assertSame('NO', $result['head_circumference_answer']->selectedLabel);

        $derived = array_values(array_filter(
            $result['derived_answers'],
            fn (DerivedAnswer $d) => $d->kind === DerivedAnswer::KIND_HEAD_CIRCUMFERENCE_ITEM
        ));
        $this->assertCount(1, $derived);
        $this->assertSame('NO', $derived[0]->value);
    }

    public function test_head_circumference_out_of_band_selects_the_red_option(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            sexKey: 'male',
            headCircumferenceMm: 400, // above the [355,390] band
        );

        $result = $this->deriver->derive($input, $this->ruleSet(), 'G1', 30);

        $this->assertSame('SI', $result['head_circumference_answer']->selectedLabel);
    }

    public function test_head_circumference_with_no_band_row_records_missing_percentile_reference(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            sexKey: 'male',
            headCircumferenceMm: 360,
        );

        // age_months = intdiv(1800, 30) = 60 -- no band defined for month 60.
        $result = $this->deriver->derive($input, $this->ruleSet(), 'G1', 1800);

        $this->assertNull($result['head_circumference_answer']);
        $missingFindings = array_values(array_filter(
            $result['findings'],
            fn (ScoringFinding $f) => $f->key === ScoringFinding::MISSING_PERCENTILE_REFERENCE
        ));
        $this->assertCount(1, $missingFindings);
    }

    public function test_head_circumference_null_measurement_records_unknown_perinatal_input(): void
    {
        $input = ScoringInput::forGroup(ageGroupCode: 'G1', sexKey: 'male', headCircumferenceMm: null);

        $result = $this->deriver->derive($input, $this->ruleSet(), 'G1', 30);

        $this->assertNull($result['head_circumference_answer']);
        $findings = array_values(array_filter(
            $result['findings'],
            fn (ScoringFinding $f) => $f->key === ScoringFinding::UNKNOWN_PERINATAL_INPUT && ($f->context['item_code'] ?? null) === 'EN1.3'
        ));
        $this->assertCount(1, $findings);
    }
}
