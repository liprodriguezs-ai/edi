<?php

namespace Modules\Screening\Services\Scoring;

use Modules\Screening\Services\Scoring\Exceptions\UnknownConditionOperatorException;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;

/**
 * Closed interpreter over the 6-operator global-rule grammar (design D13 /
 * instrument design §5.4). No `eval`, no user input, exactly one operator
 * key per condition node. An unknown operator, SET name, or color key is a
 * typed exception -- NEVER a silent `false` (REQ-SCO-007).
 *
 * ```
 * condition  := all | any | not | count | sum | color_in
 * all        := {"all":  [condition, ...]}        // AND; [] = true (catch-all)
 * any        := {"any":  [condition, ...]}        // OR;  [] = false
 * not        := {"not":  condition}
 * count      := {"count": {"of": SET, ("gte"|"eq"|"lte"): int}}
 * sum        := {"sum":   {"of": [SET, ...], "gte": int}}
 * color_in   := {"color_in": {"areas": [AREA_CODE, ...], "is": COLOR_KEY}}
 * SET        := "red_areas" | "yellow_areas" | "green_areas" | "alert_signs" | "risk_factors"
 * ```
 */
final class ConditionEvaluator
{
    private const SETS = ['red_areas', 'yellow_areas', 'green_areas', 'alert_signs', 'risk_factors'];

    private const COLOR_KEYS = [ResultColorKey::GREEN, ResultColorKey::YELLOW, ResultColorKey::RED];

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, int>  $countFacts  SET name => value
     * @param  array<string, string>  $areaColors  area code => color key
     */
    public function evaluate(array $condition, array $countFacts, array $areaColors): bool
    {
        if (count($condition) !== 1) {
            throw new UnknownConditionOperatorException(
                'Condition node must have exactly one operator key, got: '.implode(',', array_keys($condition))
            );
        }

        $operator = array_key_first($condition);
        $payload = $condition[$operator];

        return match ($operator) {
            'all' => $this->evaluateAll($payload, $countFacts, $areaColors),
            'any' => $this->evaluateAny($payload, $countFacts, $areaColors),
            'not' => ! $this->evaluate($payload, $countFacts, $areaColors),
            'count' => $this->evaluateCount($payload, $countFacts),
            'sum' => $this->evaluateSum($payload, $countFacts),
            'color_in' => $this->evaluateColorIn($payload, $areaColors),
            default => throw new UnknownConditionOperatorException("Unknown condition operator '{$operator}'."),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $conditions
     * @param  array<string, int>  $countFacts
     * @param  array<string, string>  $areaColors
     */
    private function evaluateAll(array $conditions, array $countFacts, array $areaColors): bool
    {
        foreach ($conditions as $condition) {
            if (! $this->evaluate($condition, $countFacts, $areaColors)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $conditions
     * @param  array<string, int>  $countFacts
     * @param  array<string, string>  $areaColors
     */
    private function evaluateAny(array $conditions, array $countFacts, array $areaColors): bool
    {
        foreach ($conditions as $condition) {
            if ($this->evaluate($condition, $countFacts, $areaColors)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $countFacts
     */
    private function evaluateCount(array $payload, array $countFacts): bool
    {
        $set = $payload['of'] ?? throw new UnknownConditionOperatorException("'count' condition is missing 'of'.");
        $value = $this->factValue($set, $countFacts);

        if (array_key_exists('gte', $payload)) {
            return $value >= $payload['gte'];
        }
        if (array_key_exists('eq', $payload)) {
            return $value === $payload['eq'];
        }
        if (array_key_exists('lte', $payload)) {
            return $value <= $payload['lte'];
        }

        throw new UnknownConditionOperatorException("'count' condition is missing a comparison key (gte|eq|lte).");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $countFacts
     */
    private function evaluateSum(array $payload, array $countFacts): bool
    {
        $sets = $payload['of'] ?? throw new UnknownConditionOperatorException("'sum' condition is missing 'of'.");
        if (! array_key_exists('gte', $payload)) {
            throw new UnknownConditionOperatorException("'sum' condition is missing 'gte'.");
        }

        $total = 0;
        foreach ($sets as $set) {
            $total += $this->factValue($set, $countFacts);
        }

        return $total >= $payload['gte'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $areaColors
     */
    private function evaluateColorIn(array $payload, array $areaColors): bool
    {
        $areas = $payload['areas'] ?? throw new UnknownConditionOperatorException("'color_in' condition is missing 'areas'.");
        $colorKey = $payload['is'] ?? throw new UnknownConditionOperatorException("'color_in' condition is missing 'is'.");

        if (! in_array($colorKey, self::COLOR_KEYS, true)) {
            throw new UnknownConditionOperatorException("Unknown color key '{$colorKey}' in 'color_in' condition.");
        }

        foreach ($areas as $areaCode) {
            if (($areaColors[$areaCode] ?? null) === $colorKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, int>  $countFacts
     */
    private function factValue(string $set, array $countFacts): int
    {
        if (! in_array($set, self::SETS, true)) {
            throw new UnknownConditionOperatorException("Unknown SET '{$set}'.");
        }

        return $countFacts[$set] ?? 0;
    }
}
