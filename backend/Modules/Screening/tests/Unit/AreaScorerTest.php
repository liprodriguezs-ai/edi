<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit;

use Modules\Screening\Services\Scoring\AreaScorer;
use Modules\Screening\Services\Scoring\Values\ItemAnswer;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScoringFinding;
use Modules\Screening\Tests\Unit\Support\RuleSetBuilder;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SCO-005 / design D4 -- Model A pooling, the 4-branch contract.
 */
final class AreaScorerTest extends TestCase
{
    private const LABEL_COLORS = [
        'RED' => ResultColorKey::RED,
        'YELLOW' => ResultColorKey::YELLOW,
        'GREEN' => ResultColorKey::GREEN,
    ];

    private AreaScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new AreaScorer;
    }

    private function ruleSet(): RuleSet
    {
        $builder = new RuleSetBuilder;
        $builder->official('GX', 100, 200, 'GXA')
            ->alternate('GXA');

        foreach (['MF1', 'MF2', 'MF3'] as $i => $code) {
            $builder->item('GX', $code, 'MF', self::LABEL_COLORS, $i + 1);
        }
        foreach (['AMF1', 'AMF2', 'AMF3'] as $i => $code) {
            $builder->item('GXA', $code, 'MF', self::LABEL_COLORS, $i + 1);
        }
        $builder->areaRule('GX', 'MF', 3, 2, 2, true);
        $builder->alternateAreaRule('GXA', 'MF', 3, 1, null, true);

        foreach (['MG1', 'MG2', 'MG3'] as $i => $code) {
            $builder->item('GX', $code, 'MG', self::LABEL_COLORS, $i + 1);
        }
        $builder->areaRule('GX', 'MG', 3, 1, 2, true);

        foreach (['LE1', 'LE2'] as $i => $code) {
            $builder->item('GX', $code, 'LE', self::LABEL_COLORS, $i + 1);
        }
        $builder->areaRule('GX', 'LE', 2, 1, null, true);

        foreach (['ALE1', 'ALE2'] as $i => $code) {
            $builder->item('GX', $code, 'ALE', self::LABEL_COLORS, $i + 1);
        }

        $builder->withCatchAll('GX');

        return $builder->build();
    }

    public function test_one_alternate_red_does_not_escalate_the_refutation_case(): void
    {
        // instrument design §14: own {yellow,yellow,yellow} + alternate {yellow,yellow,red} -> YELLOW.
        $answers = [
            new ItemAnswer('GX', 'MF1', 'YELLOW'),
            new ItemAnswer('GX', 'MF2', 'YELLOW'),
            new ItemAnswer('GX', 'MF3', 'YELLOW'),
            new ItemAnswer('GXA', 'AMF1', 'YELLOW'),
            new ItemAnswer('GXA', 'AMF2', 'YELLOW'),
            new ItemAnswer('GXA', 'AMF3', 'RED'),
        ];

        [$result, $findings] = $this->scorer->score('GX', 'MF', $this->ruleSet(), $answers);

        $this->assertSame(1, $result->reds);
        $this->assertSame(0, $result->greens);
        $this->assertSame(5, $result->yellows);
        $this->assertSame(ResultColorKey::YELLOW, $result->colorKey);
        $this->assertSame([], $findings);
    }

    public function test_pooled_reds_escalate_under_the_own_threshold(): void
    {
        $answers = [
            new ItemAnswer('GX', 'MF1', 'RED'),
            new ItemAnswer('GX', 'MF2', 'YELLOW'),
            new ItemAnswer('GX', 'MF3', 'YELLOW'),
            new ItemAnswer('GXA', 'AMF1', 'YELLOW'),
            new ItemAnswer('GXA', 'AMF2', 'YELLOW'),
            new ItemAnswer('GXA', 'AMF3', 'RED'),
        ];

        [$result] = $this->scorer->score('GX', 'MF', $this->ruleSet(), $answers);

        $this->assertSame(2, $result->reds);
        $this->assertSame(ResultColorKey::RED, $result->colorKey);
    }

    public function test_no_answered_alternates_is_plain_own_group_scoring(): void
    {
        $answers = [
            new ItemAnswer('GX', 'MG1', 'GREEN'),
            new ItemAnswer('GX', 'MG2', 'GREEN'),
            new ItemAnswer('GX', 'MG3', 'YELLOW'),
        ];

        [$result] = $this->scorer->score('GX', 'MG', $this->ruleSet(), $answers);

        $this->assertSame(2, $result->greens);
        $this->assertSame(0, $result->answeredAlternate);
        $this->assertSame(ResultColorKey::GREEN, $result->colorKey);
    }

    public function test_alternate_area_scoring_rule_is_never_consulted(): void
    {
        // The alternate's own area_scoring_rule has red_threshold=1 -- if it
        // were consulted, a single alternate red would turn this RED. Model A
        // (design D4) proves it is not: the OFFICIAL group's rule (threshold 2)
        // always applies.
        $ruleSet = $this->ruleSet();
        $this->assertSame(1, $ruleSet->alternateAreaRules['GXA/MF']->redThreshold);
        $this->assertSame(2, $ruleSet->areaRules['GX/MF']->redThreshold);

        $answers = [
            new ItemAnswer('GX', 'MF1', 'YELLOW'),
            new ItemAnswer('GX', 'MF2', 'YELLOW'),
            new ItemAnswer('GX', 'MF3', 'YELLOW'),
            new ItemAnswer('GXA', 'AMF1', 'RED'),
        ];

        [$result] = $this->scorer->score('GX', 'MF', $ruleSet, $answers);

        $this->assertSame(ResultColorKey::YELLOW, $result->colorKey);
    }

    public function test_null_green_threshold_skips_the_green_branch_entirely(): void
    {
        // LE: red_threshold=1, green_threshold=NULL, allows_yellow=true.
        // Two greens still yield YELLOW because the branch is SKIPPED, not
        // "0 greens required" (that distinct case is green_threshold=0, see G1).
        $answers = [
            new ItemAnswer('GX', 'LE1', 'GREEN'),
            new ItemAnswer('GX', 'LE2', 'GREEN'),
        ];

        [$result] = $this->scorer->score('GX', 'LE', $this->ruleSet(), $answers);

        $this->assertSame(ResultColorKey::YELLOW, $result->colorKey);
    }

    public function test_unanswered_own_items_are_not_counted_and_record_a_finding(): void
    {
        $answers = [
            new ItemAnswer('GX', 'MF1', 'YELLOW'),
            new ItemAnswer('GX', 'MF2', 'YELLOW'),
            // MF3 left unanswered.
        ];

        [$result, $findings] = $this->scorer->score('GX', 'MF', $this->ruleSet(), $answers);

        $this->assertSame(2, $result->answeredOwn);
        $this->assertCount(1, $findings);
        $this->assertSame(ScoringFinding::UNANSWERED_ITEM, $findings[0]->key);
        $this->assertSame('MF3', $findings[0]->context['item_code']);
    }

    public function test_unanswered_alternate_items_do_not_record_a_finding(): void
    {
        $answers = [
            new ItemAnswer('GX', 'MF1', 'YELLOW'),
            new ItemAnswer('GX', 'MF2', 'YELLOW'),
            new ItemAnswer('GX', 'MF3', 'YELLOW'),
            new ItemAnswer('GXA', 'AMF1', 'RED'),
            // AMF2/AMF3 left unanswered -- routine, not a gap.
        ];

        [, $findings] = $this->scorer->score('GX', 'MF', $this->ruleSet(), $answers);

        $this->assertSame([], $findings);
    }

    public function test_ale_is_never_a_semaphore_area(): void
    {
        $ruleSet = $this->ruleSet();

        $this->assertNotContains(RuleSet::ALE_AREA_CODE, $ruleSet->semaphoreAreaCodesInGroup('GX'));
    }
}
