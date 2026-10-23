<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit;

use Modules\Screening\Services\RuleSetLoading\CsvRuleSetLoader;
use Modules\Screening\Services\Scoring\AgeGroupResolver;
use Modules\Screening\Services\Scoring\AgeResolver;
use Modules\Screening\Services\Scoring\Exceptions\ScreeningNotApplicableException;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScoringFinding;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SCO-003 / design D7.
 */
final class AgeGroupResolverTest extends TestCase
{
    private AgeGroupResolver $resolver;

    private RuleSet $ruleSet;

    /** @var array<string, array{0: int, 1: int}> */
    private const OFFICIAL_RANGES = [
        'G1' => [30, 59], 'G2' => [60, 89], 'G3' => [90, 119], 'G4' => [120, 149],
        'G5' => [150, 209], 'G6' => [210, 299], 'G7' => [300, 389], 'G8' => [390, 479],
        'G9' => [480, 569], 'G10' => [570, 749], 'G11' => [750, 929], 'G12' => [930, 1109],
        'G13' => [1110, 1469], 'G14' => [1470, 1799], 'G15' => [1800, 2159],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AgeGroupResolver;
        $this->ruleSet = (new CsvRuleSetLoader)->build('edi-legacy-2013', AgeResolver::MODE_THIRTY_DAY_MONTH);
    }

    public function test_all_fifteen_official_ranges_resolve_to_their_group(): void
    {
        foreach (self::OFFICIAL_RANGES as $groupCode => [$min, $max]) {
            foreach ([$min, $max] as $days) {
                $result = $this->resolver->resolveGroup($this->ruleSet, $days, null);
                $this->assertSame($groupCode, $result['applied_group_code'], "day {$days} should resolve to {$groupCode}");
                $this->assertFalse($result['substituted']);
                $this->assertSame([], $result['findings']);
            }
        }
    }

    public function test_premature_substitution_moves_g3_to_g1(): void
    {
        // design REQ-SCO-003 scenario: 100 chronological days (G3) with
        // corrected age 44 days (G1).
        $result = $this->resolver->resolveGroup($this->ruleSet, 100, 44);

        $this->assertSame('G1', $result['applied_group_code']);
        $this->assertTrue($result['substituted']);
        $this->assertCount(1, $result['findings']);
        $this->assertSame(ScoringFinding::AGE_GROUP_SUBSTITUTED_BY_CORRECTED_AGE, $result['findings'][0]->key);
        $this->assertSame('G3', $result['findings'][0]->context['chronological_group_code']);
        $this->assertSame('G1', $result['findings'][0]->context['corrected_group_code']);
    }

    public function test_corrected_age_in_the_same_group_does_not_substitute(): void
    {
        // 95 and 91 chronological/corrected days are both inside G3 (90-119).
        $result = $this->resolver->resolveGroup($this->ruleSet, 95, 91);

        $this->assertSame('G3', $result['applied_group_code']);
        $this->assertFalse($result['substituted']);
        $this->assertSame([], $result['findings']);
    }

    public function test_chronological_age_below_thirty_days_throws(): void
    {
        $this->expectException(ScreeningNotApplicableException::class);
        $this->resolver->resolveGroup($this->ruleSet, 29, null);
    }

    public function test_chronological_age_above_two_thousand_one_hundred_fifty_nine_days_throws(): void
    {
        $this->expectException(ScreeningNotApplicableException::class);
        $this->resolver->resolveGroup($this->ruleSet, 2160, null);
    }

    public function test_corrected_age_below_thirty_days_throws(): void
    {
        $this->expectException(ScreeningNotApplicableException::class);
        $this->resolver->resolveGroup($this->ruleSet, 65, 20);
    }
}
