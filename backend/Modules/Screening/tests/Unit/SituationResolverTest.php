<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit;

use Modules\Screening\Services\Scoring\SituationResolver;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\SituationKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SCO-008 / design D12 -- the full 7-state x sis_code matrix.
 */
final class SituationResolverTest extends TestCase
{
    private SituationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SituationResolver;
    }

    public static function matrixProvider(): array
    {
        $g = ResultColorKey::GREEN;
        $y = ResultColorKey::YELLOW;
        $r = ResultColorKey::RED;

        return [
            'first_time_green' => [null, $g, SituationKey::FIRST_TIME_GREEN, 1],
            'first_time_yellow' => [null, $y, SituationKey::FIRST_TIME_YELLOW, 2],
            'first_time_red' => [null, $r, SituationKey::FIRST_TIME_RED, 3],
            'green_to_green_unchanged' => [$g, $g, SituationKey::UNCHANGED, 6],
            'green_to_yellow_worsened' => [$g, $y, SituationKey::WORSENED, 6],
            'green_to_red_worsened' => [$g, $r, SituationKey::WORSENED, 6],
            'yellow_to_green_recovered_from_yellow' => [$y, $g, SituationKey::RECOVERED_FROM_YELLOW, 4],
            'yellow_to_yellow_unchanged' => [$y, $y, SituationKey::UNCHANGED, 6],
            'yellow_to_red_worsened' => [$y, $r, SituationKey::WORSENED, 6],
            'red_to_green_recovered_from_red' => [$r, $g, SituationKey::RECOVERED_FROM_RED, 5],
            'red_to_yellow_recovered_from_red' => [$r, $y, SituationKey::RECOVERED_FROM_RED, 5],
            'red_to_red_unchanged' => [$r, $r, SituationKey::UNCHANGED, 6],
        ];
    }

    #[DataProvider('matrixProvider')]
    public function test_full_situation_matrix(?string $previous, string $current, string $expectedKey, int $expectedSisCode): void
    {
        $outcome = $this->resolver->resolveSituation($previous, $current);

        $this->assertSame($expectedKey, $outcome->situationKey);
        $this->assertSame($expectedSisCode, $outcome->sisCode);
    }

    public function test_unchanged_and_worsened_are_distinct_states_sharing_sis_code_six(): void
    {
        $worsened = $this->resolver->resolveSituation(ResultColorKey::GREEN, ResultColorKey::RED);
        $unchanged = $this->resolver->resolveSituation(ResultColorKey::RED, ResultColorKey::RED);

        $this->assertSame(SituationKey::WORSENED, $worsened->situationKey);
        $this->assertSame(SituationKey::UNCHANGED, $unchanged->situationKey);
        $this->assertSame(6, $worsened->sisCode);
        $this->assertSame(6, $unchanged->sisCode);
        $this->assertNotSame($worsened->situationKey, $unchanged->situationKey);
    }
}
