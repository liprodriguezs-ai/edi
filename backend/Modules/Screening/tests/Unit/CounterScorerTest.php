<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit;

use Modules\Screening\Services\Scoring\CounterScorer;
use Modules\Screening\Services\Scoring\Values\ItemAnswer;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Tests\Unit\Support\RuleSetBuilder;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SCO-006 -- alert_signs (yellow ALE answers) and risk_factors
 * (yes-after-derivation) counters.
 */
final class CounterScorerTest extends TestCase
{
    private const LABEL_COLORS = [
        'RED' => ResultColorKey::RED,
        'YELLOW' => ResultColorKey::YELLOW,
        'GREEN' => ResultColorKey::GREEN,
    ];

    private CounterScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new CounterScorer;
    }

    public function test_only_ale_yellow_answers_count_as_alert_signs(): void
    {
        $builder = new RuleSetBuilder;
        $builder->official('GX', 100, 200);
        $builder->item('GX', 'ALE1', 'ALE', self::LABEL_COLORS, 1);
        $builder->item('GX', 'ALE2', 'ALE', self::LABEL_COLORS, 2);
        $builder->item('GX', 'ALE3', 'ALE', self::LABEL_COLORS, 3);
        $builder->item('GX', 'MF1', 'MF', self::LABEL_COLORS, 1);
        $builder->areaRule('GX', 'MF', 1, 1, 1, true);
        $builder->withCatchAll('GX');
        $ruleSet = $builder->build();

        $answers = [
            new ItemAnswer('GX', 'ALE1', 'YELLOW'),
            new ItemAnswer('GX', 'ALE2', 'YELLOW'),
            new ItemAnswer('GX', 'ALE3', 'RED'),
            new ItemAnswer('GX', 'MF1', 'YELLOW'), // not ALE -- must not count
        ];

        $this->assertSame(2, $this->scorer->alertSigns('GX', $ruleSet, $answers));
    }

    public function test_unanswered_risk_factors_count_zero(): void
    {
        $riskFactorAnswers = ['FRB1' => false, 'FRB2' => null, 'FRB3' => true];

        $this->assertSame(1, $this->scorer->riskFactors($riskFactorAnswers));
    }

    public function test_answers_for_a_different_group_do_not_count_as_alert_signs(): void
    {
        $builder = new RuleSetBuilder;
        $builder->official('GX', 100, 200);
        $builder->item('GX', 'ALE1', 'ALE', self::LABEL_COLORS, 1);
        $builder->withCatchAll('GX');
        $ruleSet = $builder->build();

        $answers = [
            new ItemAnswer('GXA', 'ALE1', 'YELLOW'), // wrong group -- must not count
        ];

        $this->assertSame(0, $this->scorer->alertSigns('GX', $ruleSet, $answers));
    }
}
