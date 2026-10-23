<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit;

use Modules\Screening\Services\RuleSetLoading\CsvRuleSetLoader;
use Modules\Screening\Services\Scoring\AgeResolver;
use Modules\Screening\Services\Scoring\GlobalScorer;
use Modules\Screening\Services\Scoring\Values\AreaResult;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SCO-007 -- first-match-wins over the applied official group's global
 * rules. Uses the REAL `edi-legacy-2013` content (design §5.2's documented
 * shapes) so these tests double as a guard against grammar drift.
 */
final class GlobalScorerTest extends TestCase
{
    private GlobalScorer $scorer;

    private RuleSet $ruleSet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new GlobalScorer;
        $this->ruleSet = (new CsvRuleSetLoader)->build('edi-legacy-2013', AgeResolver::MODE_THIRTY_DAY_MONTH);
    }

    /**
     * @param  array<string, string>  $colorsByArea
     * @return array<int, AreaResult>
     */
    private function areaResults(array $colorsByArea): array
    {
        $results = [];
        foreach ($colorsByArea as $areaCode => $colorKey) {
            $results[] = new AreaResult($areaCode, $colorKey, 0, 0, 0, 0, 0, 1, 1, true);
        }

        return $results;
    }

    public function test_g1_escalates_on_ale_plus_frb_sum(): void
    {
        $colors = array_fill_keys($this->ruleSet->semaphoreAreaCodesInGroup('G1'), ResultColorKey::GREEN);

        $result = $this->scorer->score('G1', $this->ruleSet, $this->areaResults($colors), alertSigns: 1, riskFactors: 1);

        $this->assertSame(ResultColorKey::YELLOW, $result['color_key']);
        $this->assertSame('yellow_social', $result['message_key']);
        $this->assertSame(20, $result['sequence']);
    }

    public function test_g1_stays_green_when_ale_plus_frb_sum_is_below_two(): void
    {
        $colors = array_fill_keys($this->ruleSet->semaphoreAreaCodesInGroup('G1'), ResultColorKey::GREEN);

        $result = $this->scorer->score('G1', $this->ruleSet, $this->areaResults($colors), alertSigns: 1, riskFactors: 0);

        $this->assertSame(ResultColorKey::GREEN, $result['color_key']);
    }

    public function test_groups_from_g5_onward_ignore_ale_and_frb(): void
    {
        // instrument design D15: the legacy scope bug is faithful behavior.
        $colors = array_fill_keys($this->ruleSet->semaphoreAreaCodesInGroup('G10'), ResultColorKey::GREEN);

        $result = $this->scorer->score('G10', $this->ruleSet, $this->areaResults($colors), alertSigns: 5, riskFactors: 3);

        $this->assertSame(ResultColorKey::GREEN, $result['color_key']);
    }

    public function test_first_match_wins_over_a_later_matching_rule(): void
    {
        // G2: sequence 10 (red) matches on count(red_areas>=1); sequence 20
        // (yellow_development) would ALSO match via color_in(MG,yellow) --
        // sequence 10 must win because it comes first.
        $colors = array_fill_keys($this->ruleSet->semaphoreAreaCodesInGroup('G2'), ResultColorKey::GREEN);
        $colors['MG'] = ResultColorKey::YELLOW;
        // Force exactly one RED area too, so BOTH rule 10 and rule 20 conditions are satisfiable.
        $colors['LE'] = ResultColorKey::RED;

        $result = $this->scorer->score('G2', $this->ruleSet, $this->areaResults($colors), alertSigns: 0, riskFactors: 0);

        $this->assertSame(ResultColorKey::RED, $result['color_key']);
        $this->assertSame(10, $result['sequence']);
    }

    public function test_g2_yellow_development_fires_on_mg_mf_le_areas(): void
    {
        $colors = array_fill_keys($this->ruleSet->semaphoreAreaCodesInGroup('G2'), ResultColorKey::GREEN);
        $colors['MF'] = ResultColorKey::YELLOW;

        $result = $this->scorer->score('G2', $this->ruleSet, $this->areaResults($colors), alertSigns: 0, riskFactors: 0);

        $this->assertSame(ResultColorKey::YELLOW, $result['color_key']);
        $this->assertSame('yellow_development', $result['message_key']);
        $this->assertSame(20, $result['sequence']);
    }

    public function test_g13_yellow_social_fires_on_so_or_co(): void
    {
        // G13-G15 shape: rule 30 widens color_in to ["SO","CO"] (design §5.2).
        $colors = array_fill_keys($this->ruleSet->semaphoreAreaCodesInGroup('G13'), ResultColorKey::GREEN);
        $colors['CO'] = ResultColorKey::YELLOW;

        $result = $this->scorer->score('G13', $this->ruleSet, $this->areaResults($colors), alertSigns: 0, riskFactors: 0);

        $this->assertSame(ResultColorKey::YELLOW, $result['color_key']);
        $this->assertSame('yellow_social', $result['message_key']);
        $this->assertSame(30, $result['sequence']);
    }

    public function test_all_green_hits_the_catch_all(): void
    {
        $colors = array_fill_keys($this->ruleSet->semaphoreAreaCodesInGroup('G7'), ResultColorKey::GREEN);

        $result = $this->scorer->score('G7', $this->ruleSet, $this->areaResults($colors), alertSigns: 0, riskFactors: 0);

        $this->assertSame(ResultColorKey::GREEN, $result['color_key']);
        $this->assertSame(90, $result['sequence']);
    }
}
