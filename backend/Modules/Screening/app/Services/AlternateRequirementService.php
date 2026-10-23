<?php

declare(strict_types=1);

namespace Modules\Screening\Services;

use Illuminate\Support\Collection;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\DevelopmentArea;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningAnswer;
use Modules\Screening\Services\Scoring\Values\RuleSet;

/**
 * Evaluación alterna (REQ-SCR-005): a development axis needs the alternate
 * (previous) group's items when EVERY own item of the applied group is
 * answered and every answer is yellow -- "sin haber logrado ninguno" in the
 * official manual.
 *
 * The single source of that rule: the score gate (422
 * ALTERNATE_ITEMS_REQUIRED), the answer save and the item bank all ask this
 * service, so the evaluator sees the requirement while capturing and the
 * gate can never disagree with what the screen showed.
 */
final class AlternateRequirementService
{
    public function __construct(private readonly RuleSetProvider $ruleSetProvider) {}

    /**
     * Axes of the screening that currently require the alternate items, each
     * with ALL of its alternate items (answered or not) in item-bank shape.
     *
     * @return array<int, array{development_area_id: ?string, code: string, items: array<int, array<string, mixed>>, missing_items: array<int, array<string, mixed>>}>
     */
    public function forScreening(Screening $screening): array
    {
        [$ruleSet, $appliedGroupCode, $version, $answers, $groupCodeById] = $this->context($screening);

        return $this->evaluate($ruleSet, $appliedGroupCode, $version, $answers, $groupCodeById);
    }

    /**
     * The client-facing shape: which axes need the alternate items and which
     * those items are. `missing_items` stays internal to the score gate.
     *
     * @return array<int, array{development_area_id: ?string, code: string, items: array<int, array<string, mixed>>}>
     */
    public function forClient(Screening $screening): array
    {
        return array_map(fn (array $requirement) => [
            'development_area_id' => $requirement['development_area_id'],
            'code' => $requirement['code'],
            'items' => $requirement['items'],
        ], $this->forScreening($screening));
    }

    /**
     * Soft-deletes (one by one, so each is audited) the saved alternate answers
     * whose axis no longer requires the alternate evaluation. Must run inside
     * the caller's locked transaction.
     *
     * @return int how many answers were discarded
     */
    public function discardStaleAlternateAnswers(Screening $screening): int
    {
        [$ruleSet, $appliedGroupCode, , $answers, $groupCodeById] = $this->context($screening);
        $staleIds = $this->staleAlternateAnswerIds($ruleSet, $appliedGroupCode, $answers, $groupCodeById);

        $answers->whereIn('id', $staleIds)->each(fn (ScreeningAnswer $answer) => $answer->delete());

        return count($staleIds);
    }

    /**
     * @return array{0: RuleSet, 1: string, 2: InstrumentVersion, 3: Collection<int, ScreeningAnswer>, 4: array<string, string>}
     */
    private function context(Screening $screening): array
    {
        $version = InstrumentVersion::findOrFail($screening->instrument_version_id);
        $ruleSet = $this->ruleSetProvider->for($version->key);
        $appliedGroupModel = AgeGroup::findOrFail($screening->applied_age_group_id);

        $alternateGroupCode = $ruleSet->group($appliedGroupModel->code)->alternateGroupCode;
        $alternateGroupModel = $alternateGroupCode !== null
            ? AgeGroup::where('instrument_version_id', $version->id)->where('code', $alternateGroupCode)->first()
            : null;

        $groupCodeById = [$appliedGroupModel->id => $appliedGroupModel->code];
        if ($alternateGroupModel !== null) {
            $groupCodeById[$alternateGroupModel->id] = $alternateGroupCode;
        }

        $answers = ScreeningAnswer::with(['screening_item', 'screening_item_option'])
            ->where('screening_id', $screening->id)
            ->get();

        return [$ruleSet, $appliedGroupModel->code, $version, $answers, $groupCodeById];
    }

    /**
     * Pure evaluation over already-loaded answers (the score pipeline reuses it
     * inside its own locked transaction).
     *
     * @param  Collection<int, ScreeningAnswer>  $answers
     * @param  array<string, string>  $groupCodeById  age_group id => group code, applied + alternate
     * @return array<int, array{development_area_id: ?string, code: string, items: array<int, array<string, mixed>>, missing_items: array<int, array<string, mixed>>}>
     */
    public function evaluate(RuleSet $ruleSet, string $appliedGroupCode, InstrumentVersion $version, Collection $answers, array $groupCodeById): array
    {
        $alternateGroupCode = $ruleSet->group($appliedGroupCode)->alternateGroupCode;
        if ($alternateGroupCode === null) {
            return [];
        }

        $answeredLabels = $this->answeredLabelsByGroupItem($answers, $groupCodeById);
        $requirements = [];

        foreach ($this->axisCodesRequiringAlternate($ruleSet, $appliedGroupCode, $answeredLabels) as $areaCode) {
            $alternateCodes = array_map(
                fn ($item) => $item->itemCode,
                $ruleSet->itemsInGroupArea($alternateGroupCode, $areaCode),
            );

            $items = $this->bankItems($version, $alternateGroupCode, $alternateCodes);
            $missing = array_values(array_filter(
                $items,
                fn (array $item) => ! isset($answeredLabels["{$alternateGroupCode}/{$item['code']}"]),
            ));

            $requirements[] = [
                'development_area_id' => DevelopmentArea::where('code', $areaCode)->value('id'),
                'code' => $areaCode,
                'items' => $items,
                'missing_items' => $missing,
            ];
        }

        return $requirements;
    }

    /**
     * Ids of saved alternate answers whose axis does NOT require the alternate
     * evaluation any more (an own item was changed to achieved). They must not
     * count towards the axis result.
     *
     * @param  Collection<int, ScreeningAnswer>  $answers
     * @param  array<string, string>  $groupCodeById
     * @return array<int, string>
     */
    public function staleAlternateAnswerIds(RuleSet $ruleSet, string $appliedGroupCode, Collection $answers, array $groupCodeById): array
    {
        $alternateGroupCode = $ruleSet->group($appliedGroupCode)->alternateGroupCode;
        if ($alternateGroupCode === null) {
            return [];
        }

        $answeredLabels = $this->answeredLabelsByGroupItem($answers, $groupCodeById);
        $requiredAxes = $this->axisCodesRequiringAlternate($ruleSet, $appliedGroupCode, $answeredLabels);

        $staleItemCodes = [];
        foreach ($this->developmentAxisCodesInGroup($ruleSet, $appliedGroupCode) as $areaCode) {
            if (in_array($areaCode, $requiredAxes, true)) {
                continue;
            }
            foreach ($ruleSet->itemsInGroupArea($alternateGroupCode, $areaCode) as $item) {
                $staleItemCodes[] = $item->itemCode;
            }
        }

        return $answers
            ->filter(fn (ScreeningAnswer $answer) => ($groupCodeById[$answer->screening_item->age_group_id] ?? null) === $alternateGroupCode
                && in_array($answer->screening_item->code, $staleItemCodes, true))
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, string>  $answeredLabels
     * @return array<int, string>
     */
    private function axisCodesRequiringAlternate(RuleSet $ruleSet, string $appliedGroupCode, array $answeredLabels): array
    {
        $axes = [];
        foreach ($this->developmentAxisCodesInGroup($ruleSet, $appliedGroupCode) as $areaCode) {
            $ownItems = $ruleSet->itemsInGroupArea($appliedGroupCode, $areaCode);
            if ($ownItems === []) {
                continue;
            }

            $noneAchieved = true;
            foreach ($ownItems as $item) {
                $label = $answeredLabels["{$appliedGroupCode}/{$item->itemCode}"] ?? null;
                if ($label === null || $item->colorForLabel($label) !== 'yellow') {
                    $noneAchieved = false;
                    break;
                }
            }

            if ($noneAchieved) {
                $axes[] = $areaCode;
            }
        }

        return $axes;
    }

    /**
     * @return array<int, string>
     */
    private function developmentAxisCodesInGroup(RuleSet $ruleSet, string $appliedGroupCode): array
    {
        $developmentAxisCodes = DevelopmentArea::where('is_development_axis', true)->pluck('code')->all();

        return array_values(array_filter(
            $ruleSet->semaphoreAreaCodesInGroup($appliedGroupCode),
            fn (string $areaCode) => in_array($areaCode, $developmentAxisCodes, true),
        ));
    }

    /**
     * @param  Collection<int, ScreeningAnswer>  $answers
     * @param  array<string, string>  $groupCodeById
     * @return array<string, string> "GROUP/ITEM" => selected option label
     */
    private function answeredLabelsByGroupItem(Collection $answers, array $groupCodeById): array
    {
        $labels = [];
        foreach ($answers as $answer) {
            $groupCode = $groupCodeById[$answer->screening_item->age_group_id] ?? null;
            if ($groupCode === null) {
                continue;
            }
            $labels["{$groupCode}/{$answer->screening_item->code}"] = $answer->screening_item_option->label;
        }

        return $labels;
    }

    /**
     * Alternate items in the same shape as the item bank (`is_alternate: true`,
     * never locked), in the instrument's order.
     *
     * @param  array<int, string>  $itemCodes
     * @return array<int, array<string, mixed>>
     */
    private function bankItems(InstrumentVersion $version, string $groupCode, array $itemCodes): array
    {
        $groupId = AgeGroup::where('instrument_version_id', $version->id)->where('code', $groupCode)->value('id');
        if ($groupId === null || $itemCodes === []) {
            return [];
        }

        return ScreeningItem::with('options')
            ->where('age_group_id', $groupId)
            ->whereIn('code', $itemCodes)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ScreeningItem $item) => [
                'screening_item_id' => $item->id,
                'code' => $item->code,
                'question' => $item->question,
                'is_alternate' => true,
                'locked' => false,
                'locked_reason' => null,
                'options' => $item->options->map(fn ($option) => [
                    'screening_item_option_id' => $option->id,
                    'label' => $option->label,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
