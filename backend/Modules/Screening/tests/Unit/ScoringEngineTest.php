<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit;

use DateTimeImmutable;
use Modules\Screening\Services\Scoring\ScoringEngine;
use Modules\Screening\Services\Scoring\Values\ItemAnswer;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScoringInput;
use Modules\Screening\Services\Scoring\Values\SituationKey;
use Modules\Screening\Tests\Unit\Support\RuleSetBuilder;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SCO-001/009 -- end-to-end composition, determinism, and trace
 * explainability.
 */
final class ScoringEngineTest extends TestCase
{
    private const LABEL_COLORS = [
        'RED' => ResultColorKey::RED,
        'YELLOW' => ResultColorKey::YELLOW,
        'GREEN' => ResultColorKey::GREEN,
    ];

    private ScoringEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ScoringEngine;
    }

    private function ruleSet(): RuleSet
    {
        $builder = new RuleSetBuilder;
        $builder->official('G1', 30, 59);
        $builder->item('G1', 'MG1', 'MG', self::LABEL_COLORS, 1);
        $builder->item('G1', 'MG2', 'MG', self::LABEL_COLORS, 2);
        $builder->areaRule('G1', 'MG', 2, 1, 2, true);
        $builder->item('G1', 'ALE1', 'ALE', self::LABEL_COLORS, 1);
        $builder->riskFactor('FRB1');
        $builder->globalRule('G1', 10, ['count' => ['of' => 'red_areas', 'gte' => 1]], ResultColorKey::RED, 'red');
        $builder->globalRule('G1', 20, ['color_in' => ['areas' => ['MG'], 'is' => ResultColorKey::YELLOW]], ResultColorKey::YELLOW, 'yellow_development');
        $builder->withCatchAll('G1', 9999);

        return $builder->build();
    }

    public function test_for_group_path_scores_end_to_end(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            answers: [
                new ItemAnswer('G1', 'MG1', 'GREEN'),
                new ItemAnswer('G1', 'MG2', 'GREEN'),
                new ItemAnswer('G1', 'ALE1', 'GREEN'),
            ],
            riskFactorAnswers: ['FRB1' => false],
        );

        $result = $this->engine->score($input, $this->ruleSet());

        $this->assertSame('G1', $result->appliedAgeGroupCode);
        $this->assertFalse($result->ageGroupSubstituted);
        $this->assertNull($result->chronologicalAgeDays);
        $this->assertNull($result->correctedAgeDays);
        $this->assertSame(ResultColorKey::GREEN, $result->globalColorKey);
        $this->assertSame(9999, $result->matchedGlobalRuleSequence);
        $this->assertSame(0, $result->alertSignCount);
        $this->assertSame(0, $result->riskFactorCount);
        $this->assertSame([], $result->findings);
        $this->assertNotNull($result->situation);
        $this->assertSame(SituationKey::FIRST_TIME_GREEN, $result->situation->situationKey);
        $this->assertSame(1, $result->situation->sisCode);
    }

    public function test_for_dates_path_resolves_the_group_from_age(): void
    {
        $input = ScoringInput::forDates(
            birthDate: new DateTimeImmutable('2023-01-01'),
            testDate: new DateTimeImmutable('2023-02-01'), // 30 days, thirty_day_month
            answers: [
                new ItemAnswer('G1', 'MG1', 'GREEN'),
                new ItemAnswer('G1', 'MG2', 'GREEN'),
            ],
        );

        $result = $this->engine->score($input, $this->ruleSet());

        $this->assertSame('G1', $result->appliedAgeGroupCode);
        $this->assertSame(30, $result->chronologicalAgeDays);
        $this->assertNull($result->correctedAgeDays);
        $this->assertFalse($result->ageGroupSubstituted);
    }

    public function test_determinism_repeated_scoring_is_byte_stable(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            answers: [
                new ItemAnswer('G1', 'MG1', 'YELLOW'),
                new ItemAnswer('G1', 'MG2', 'GREEN'),
            ],
        );
        $ruleSet = $this->ruleSet();

        $first = $this->engine->score($input, $ruleSet)->toArray();
        $second = $this->engine->score($input, $ruleSet)->toArray();

        $this->assertSame($first, $second);
    }

    public function test_trace_explains_area_counts_thresholds_and_matched_sequence(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            answers: [
                new ItemAnswer('G1', 'MG1', 'YELLOW'),
                new ItemAnswer('G1', 'MG2', 'YELLOW'),
            ],
        );

        $result = $this->engine->score($input, $this->ruleSet());
        $trace = $result->toArray();

        $mgArea = $trace['area_results'][0];
        $this->assertSame('MG', $mgArea['area_code']);
        $this->assertSame(2, $mgArea['yellows']);
        $this->assertSame(1, $mgArea['red_threshold']);
        $this->assertSame(2, $mgArea['green_threshold']);
        $this->assertSame(ResultColorKey::YELLOW, $trace['global_color_key']);
        $this->assertSame(20, $trace['matched_global_rule_sequence']);
        $this->assertSame('yellow_development', $trace['message_key']);
    }

    public function test_serialized_result_contains_no_uuids_timestamps_or_legacy_ids(): void
    {
        $input = ScoringInput::forGroup(
            ageGroupCode: 'G1',
            answers: [
                new ItemAnswer('G1', 'MG1', 'GREEN'),
                new ItemAnswer('G1', 'MG2', 'GREEN'),
            ],
        );

        $trace = $this->engine->score($input, $this->ruleSet())->toArray();
        $flattened = json_encode($trace);

        $this->assertDoesNotMatchRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            $flattened,
            'no UUID-shaped value should appear in the serialized trace'
        );
        $this->assertStringNotContainsString('legacy_id', $flattened);
        $this->assertStringNotContainsString('created_at', $flattened);
        $this->assertStringNotContainsString('updated_at', $flattened);
    }
}
