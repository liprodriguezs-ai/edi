<?php

declare(strict_types=1);

namespace Modules\Screening\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Instrument\Models\RiskFactor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Instrument\Models\ScreeningItemOption;
use Modules\Screening\Exceptions\ScreeningDomainException;
use Modules\Screening\Models\Screening;
use Modules\Screening\Models\ScreeningAnswer;
use Modules\Screening\Models\ScreeningRiskFactorAnswer;
use Modules\Screening\Models\ScreeningStatus;

/**
 * Batch idempotent answer upsert (design D4/D14/D19, REQ-SCR-004). Every
 * answer's color is resolved server-side from the selected option -- the
 * client payload carries only ids. A mutation while `scored` reverts the
 * status to `draft` and clears the header result fields (D14); score runs
 * are never deleted. Runs entirely inside one lockForUpdate transaction.
 */
final class ScreeningAnswerService
{
    public function __construct(private readonly AlternateRequirementService $alternateRequirements) {}

    /**
     * @param  array{answers?: array<int, array{screening_item_id: string, screening_item_option_id: string}>, risk_factor_answers?: array<int, array{risk_factor_id: string, is_present: bool}>}  $data
     */
    public function upsert(Screening $screening, array $data): Screening
    {
        return DB::transaction(function () use ($screening, $data): Screening {
            /** @var Screening $locked */
            $locked = Screening::query()->whereKey($screening->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->screening_status_id, [
                ScreeningStatus::COMPLETED,
                ScreeningStatus::INVALIDATED,
            ], true)) {
                throw ScreeningDomainException::screeningFrozen();
            }

            $answers = $data['answers'] ?? [];
            $riskFactorAnswers = $data['risk_factor_answers'] ?? [];

            $allowedGroupIds = array_filter([$locked->applied_age_group_id, $this->alternateGroupId($locked)]);

            $itemModels = $answers === [] ? collect() : ScreeningItem::query()
                ->whereIn('id', array_column($answers, 'screening_item_id'))
                ->get()
                ->keyBy('id');

            $offendingItemIds = [];
            foreach ($answers as $answer) {
                $item = $itemModels->get($answer['screening_item_id']);
                if ($item === null || ! in_array($item->age_group_id, $allowedGroupIds, true)) {
                    $offendingItemIds[] = $answer['screening_item_id'];
                }
            }

            if ($offendingItemIds !== []) {
                throw ValidationException::withMessages([
                    'answers' => ['Uno o más reactivos no pertenecen al grupo aplicado ni a su grupo alterno.'],
                    'offending_item_ids' => $offendingItemIds,
                ]);
            }

            $optionModels = $answers === [] ? collect() : ScreeningItemOption::query()
                ->whereIn('id', array_column($answers, 'screening_item_option_id'))
                ->get()
                ->keyBy('id');

            $offendingOptionIds = [];
            foreach ($answers as $answer) {
                $option = $optionModels->get($answer['screening_item_option_id']);
                if ($option === null || $option->screening_item_id !== $answer['screening_item_id']) {
                    $offendingOptionIds[] = $answer['screening_item_option_id'];
                }
            }

            if ($offendingOptionIds !== []) {
                throw ValidationException::withMessages([
                    'answers' => ['Una o más opciones no pertenecen al reactivo indicado.'],
                    'offending_option_ids' => $offendingOptionIds,
                ]);
            }

            $riskFactorModels = $riskFactorAnswers === [] ? collect() : RiskFactor::query()
                ->where('instrument_version_id', $locked->instrument_version_id)
                ->whereIn('id', array_column($riskFactorAnswers, 'risk_factor_id'))
                ->get()
                ->keyBy('id');

            $offendingRiskFactorIds = [];
            foreach ($riskFactorAnswers as $rfAnswer) {
                if (! $riskFactorModels->has($rfAnswer['risk_factor_id'])) {
                    $offendingRiskFactorIds[] = $rfAnswer['risk_factor_id'];
                }
            }

            if ($offendingRiskFactorIds !== []) {
                throw ValidationException::withMessages([
                    'risk_factor_answers' => ['Uno o más factores de riesgo no pertenecen a la versión del instrumento de la Prueba EDI.'],
                    'offending_risk_factor_ids' => $offendingRiskFactorIds,
                ]);
            }

            $changed = false;

            foreach ($answers as $answer) {
                $option = $optionModels->get($answer['screening_item_option_id']);

                $existing = ScreeningAnswer::query()
                    ->where('screening_id', $locked->id)
                    ->where('screening_item_id', $answer['screening_item_id'])
                    ->first();

                if ($existing === null || $existing->screening_item_option_id !== $option->id) {
                    $changed = true;
                }

                ScreeningAnswer::updateOrCreate(
                    ['screening_id' => $locked->id, 'screening_item_id' => $answer['screening_item_id']],
                    [
                        'screening_item_option_id' => $option->id,
                        'result_color_id' => $option->result_color_id,
                        'is_auto_derived' => false,
                    ],
                );
            }

            foreach ($riskFactorAnswers as $rfAnswer) {
                $existing = ScreeningRiskFactorAnswer::query()
                    ->where('screening_id', $locked->id)
                    ->where('risk_factor_id', $rfAnswer['risk_factor_id'])
                    ->first();

                if ($existing === null || (bool) $existing->is_present !== (bool) $rfAnswer['is_present']) {
                    $changed = true;
                }

                ScreeningRiskFactorAnswer::updateOrCreate(
                    ['screening_id' => $locked->id, 'risk_factor_id' => $rfAnswer['risk_factor_id']],
                    ['is_present' => (bool) $rfAnswer['is_present'], 'is_auto_derived' => false],
                );
            }

            // An own answer changed to "achieved" means the axis no longer needs
            // the previous group: its alternate answers must stop counting.
            if ($answers !== [] && $this->alternateRequirements->discardStaleAlternateAnswers($locked) > 0) {
                $changed = true;
            }

            if ($changed && $locked->screening_status_id === ScreeningStatus::SCORED) {
                $locked->forceFill([
                    'screening_status_id' => ScreeningStatus::DRAFT,
                    'result_color_id' => null,
                    'situation_id' => null,
                    'alert_sign_count' => null,
                    'risk_factor_count' => null,
                    'global_result_message_id' => null,
                    'scored_at' => null,
                ])->save();
            }

            return $locked->fresh();
        });
    }

    private function alternateGroupId(Screening $screening): ?string
    {
        $appliedGroup = $screening->applied_age_group;

        return $appliedGroup?->alternate_age_group_id;
    }
}
