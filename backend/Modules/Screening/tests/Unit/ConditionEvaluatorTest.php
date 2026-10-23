<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit;

use Modules\Screening\Services\Scoring\ConditionEvaluator;
use Modules\Screening\Services\Scoring\Exceptions\UnknownConditionOperatorException;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SCO-007 / design D13 -- the closed 6-operator grammar.
 */
final class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ConditionEvaluator;
    }

    private function facts(): array
    {
        return ['red_areas' => 1, 'yellow_areas' => 2, 'green_areas' => 4, 'alert_signs' => 1, 'risk_factors' => 1];
    }

    private function areaColors(): array
    {
        return ['MG' => ResultColorKey::GREEN, 'MF' => ResultColorKey::YELLOW, 'LE' => ResultColorKey::RED];
    }

    public function test_all_empty_is_true_the_catch_all(): void
    {
        $this->assertTrue($this->evaluator->evaluate(['all' => []], $this->facts(), $this->areaColors()));
    }

    public function test_any_empty_is_false(): void
    {
        $this->assertFalse($this->evaluator->evaluate(['any' => []], $this->facts(), $this->areaColors()));
    }

    public function test_all_is_and(): void
    {
        $condition = ['all' => [
            ['count' => ['of' => 'red_areas', 'gte' => 1]],
            ['count' => ['of' => 'green_areas', 'gte' => 4]],
        ]];
        $this->assertTrue($this->evaluator->evaluate($condition, $this->facts(), $this->areaColors()));

        $condition2 = ['all' => [
            ['count' => ['of' => 'red_areas', 'gte' => 1]],
            ['count' => ['of' => 'green_areas', 'gte' => 5]],
        ]];
        $this->assertFalse($this->evaluator->evaluate($condition2, $this->facts(), $this->areaColors()));
    }

    public function test_any_is_or(): void
    {
        $condition = ['any' => [
            ['count' => ['of' => 'red_areas', 'gte' => 5]],
            ['count' => ['of' => 'green_areas', 'gte' => 4]],
        ]];
        $this->assertTrue($this->evaluator->evaluate($condition, $this->facts(), $this->areaColors()));
    }

    public function test_not_negates(): void
    {
        $condition = ['not' => ['count' => ['of' => 'red_areas', 'gte' => 5]]];
        $this->assertTrue($this->evaluator->evaluate($condition, $this->facts(), $this->areaColors()));
    }

    public function test_count_supports_gte_eq_lte(): void
    {
        $this->assertTrue($this->evaluator->evaluate(['count' => ['of' => 'red_areas', 'gte' => 1]], $this->facts(), $this->areaColors()));
        $this->assertTrue($this->evaluator->evaluate(['count' => ['of' => 'red_areas', 'eq' => 1]], $this->facts(), $this->areaColors()));
        $this->assertTrue($this->evaluator->evaluate(['count' => ['of' => 'yellow_areas', 'lte' => 2]], $this->facts(), $this->areaColors()));
        $this->assertFalse($this->evaluator->evaluate(['count' => ['of' => 'yellow_areas', 'lte' => 1]], $this->facts(), $this->areaColors()));
    }

    public function test_sum_adds_multiple_sets(): void
    {
        $condition = ['sum' => ['of' => ['alert_signs', 'risk_factors'], 'gte' => 2]];
        $this->assertTrue($this->evaluator->evaluate($condition, $this->facts(), $this->areaColors()));

        $condition2 = ['sum' => ['of' => ['alert_signs', 'risk_factors'], 'gte' => 3]];
        $this->assertFalse($this->evaluator->evaluate($condition2, $this->facts(), $this->areaColors()));
    }

    public function test_color_in_matches_any_listed_area(): void
    {
        $this->assertTrue($this->evaluator->evaluate(
            ['color_in' => ['areas' => ['MG', 'MF'], 'is' => ResultColorKey::YELLOW]],
            $this->facts(),
            $this->areaColors(),
        ));
        $this->assertFalse($this->evaluator->evaluate(
            ['color_in' => ['areas' => ['MG'], 'is' => ResultColorKey::YELLOW]],
            $this->facts(),
            $this->areaColors(),
        ));
    }

    public function test_nested_conditions(): void
    {
        $condition = ['all' => [
            ['any' => [
                ['count' => ['of' => 'yellow_areas', 'gte' => 1]],
                ['sum' => ['of' => ['alert_signs', 'risk_factors'], 'gte' => 5]],
            ]],
            ['color_in' => ['areas' => ['LE'], 'is' => ResultColorKey::RED]],
        ]];

        $this->assertTrue($this->evaluator->evaluate($condition, $this->facts(), $this->areaColors()));
    }

    public function test_unknown_operator_throws(): void
    {
        $this->expectException(UnknownConditionOperatorException::class);
        $this->evaluator->evaluate(['xor' => []], $this->facts(), $this->areaColors());
    }

    public function test_unknown_set_throws(): void
    {
        $this->expectException(UnknownConditionOperatorException::class);
        $this->evaluator->evaluate(['count' => ['of' => 'blue_areas', 'gte' => 1]], $this->facts(), $this->areaColors());
    }

    public function test_unknown_color_key_throws(): void
    {
        $this->expectException(UnknownConditionOperatorException::class);
        $this->evaluator->evaluate(['color_in' => ['areas' => ['MG'], 'is' => 'purple']], $this->facts(), $this->areaColors());
    }

    public function test_multiple_operator_keys_in_one_node_throws(): void
    {
        $this->expectException(UnknownConditionOperatorException::class);
        $this->evaluator->evaluate(['all' => [], 'any' => []], $this->facts(), $this->areaColors());
    }
}
