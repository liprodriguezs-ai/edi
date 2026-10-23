<?php

namespace Modules\Instrument\Services;

use Modules\ChildRecord\Models\Sex;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\AreaScoringRule;
use Modules\Instrument\Models\GlobalResultMessage;
use Modules\Instrument\Models\GlobalScoringRule;
use Modules\Instrument\Models\HeadCircumferenceReference;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\RiskFactor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Instrument\Models\ScreeningItemOption;

/**
 * Canonical serialization + SHA-256 of a version's content (design §7 /
 * REQ-INS-003). Every array element is keyed by NATURAL keys (codes/slugs),
 * never by uuid or legacy_id, so the hash is a pure function of content --
 * identical content always hashes identically regardless of when/where it
 * was seeded. `source` is included (a meaningful provenance tag the
 * grammar/verify depend on); free-text `notes` are deliberately EXCLUDED so
 * that editing documentation never forces a hash mismatch on published rows.
 */
class InstrumentContentHasher
{
    public function hash(InstrumentVersion $version): string
    {
        return hash('sha256', $this->canonicalJson($version));
    }

    public function canonicalJson(InstrumentVersion $version): string
    {
        $ageGroups = AgeGroup::query()
            ->where('instrument_version_id', $version->id)
            ->orderBy('code')
            ->get();

        $codeById = $ageGroups->pluck('code', 'id');

        $items = ScreeningItem::query()
            ->with(['development_area:id,code', 'application_position:id,key'])
            ->where('instrument_version_id', $version->id)
            ->get();

        $itemGroupCodeById = $items->mapWithKeys(fn (ScreeningItem $i) => [
            $i->id => $codeById[$i->age_group_id] ?? null,
        ]);

        $options = ScreeningItemOption::query()
            ->with('result_color:id,key')
            ->where('instrument_version_id', $version->id)
            ->get();

        $itemById = $items->keyBy('id');

        $riskFactors = RiskFactor::query()
            ->where('instrument_version_id', $version->id)
            ->get();

        $hcRefs = HeadCircumferenceReference::query()
            ->where('instrument_version_id', $version->id)
            ->get();
        $sexKeyById = Sex::query()->pluck('key', 'id');

        $areaRules = AreaScoringRule::query()
            ->with('development_area:id,code')
            ->where('instrument_version_id', $version->id)
            ->get();

        $messages = GlobalResultMessage::query()
            ->with('result_color:id,key')
            ->where('instrument_version_id', $version->id)
            ->get();
        $messageKeyById = $messages->pluck('key', 'id');

        $globalRules = GlobalScoringRule::query()
            ->with('result_color:id,key')
            ->where('instrument_version_id', $version->id)
            ->get();

        $payload = [
            'version' => [
                'key' => $version->key,
                'age_calculation_mode' => $version->age_calculation_mode,
            ],
            'age_groups' => $ageGroups
                ->map(fn (AgeGroup $g) => [
                    'code' => $g->code,
                    'name' => $g->name,
                    'ordinal' => $g->ordinal,
                    'is_alternate' => $g->is_alternate,
                    'min_age_days' => $g->min_age_days,
                    'max_age_days' => $g->max_age_days,
                    'min_age_months' => $g->min_age_months,
                    'max_age_months' => $g->max_age_months,
                    'alternate_group_code' => $g->alternate_age_group_id !== null
                        ? ($codeById[$g->alternate_age_group_id] ?? null)
                        : null,
                    'materials_note' => $g->materials_note,
                ])
                ->sortBy('code')
                ->values()
                ->all(),
            'screening_items' => $items
                ->map(fn (ScreeningItem $i) => [
                    'group_code' => $codeById[$i->age_group_id] ?? null,
                    'area_code' => $i->development_area?->code,
                    'code' => $i->code,
                    'question' => $i->question,
                    'position_key' => $i->application_position?->key,
                    'sort_order' => $i->sort_order,
                ])
                ->sortBy([['group_code', 'asc'], ['code', 'asc']])
                ->values()
                ->all(),
            'options' => $options
                ->map(function (ScreeningItemOption $o) use ($itemById, $itemGroupCodeById) {
                    $item = $itemById[$o->screening_item_id] ?? null;

                    return [
                        'group_code' => $item !== null ? ($itemGroupCodeById[$item->id] ?? null) : null,
                        'item_code' => $item?->code,
                        'label' => $o->label,
                        'sort_order' => $o->sort_order,
                        'color_key' => $o->result_color?->key,
                    ];
                })
                ->sortBy([['group_code', 'asc'], ['item_code', 'asc'], ['label', 'asc']])
                ->values()
                ->all(),
            'risk_factors' => $riskFactors
                ->map(fn (RiskFactor $r) => [
                    'code' => $r->code,
                    'question' => $r->question,
                    'sort_order' => $r->sort_order,
                    'auto_derivation_key' => $r->auto_derivation_key,
                ])
                ->sortBy('code')
                ->values()
                ->all(),
            'head_circumference_references' => $hcRefs
                ->map(fn (HeadCircumferenceReference $h) => [
                    'sex_key' => $sexKeyById[$h->sex_id] ?? null,
                    'age_months' => $h->age_months,
                    'min_mm' => $h->min_circumference_mm,
                    'max_mm' => $h->max_circumference_mm,
                ])
                ->sortBy([['sex_key', 'asc'], ['age_months', 'asc']])
                ->values()
                ->all(),
            'area_scoring_rules' => $areaRules
                ->map(fn (AreaScoringRule $r) => [
                    'group_code' => $codeById[$r->age_group_id] ?? null,
                    'area_code' => $r->development_area?->code,
                    'item_count' => $r->item_count,
                    'red_threshold' => $r->red_threshold,
                    'green_threshold' => $r->green_threshold,
                    'allows_yellow' => $r->allows_yellow,
                    'source' => $r->source,
                ])
                ->sortBy([['group_code', 'asc'], ['area_code', 'asc']])
                ->values()
                ->all(),
            'global_scoring_rules' => $globalRules
                ->map(fn (GlobalScoringRule $r) => [
                    'group_code' => $codeById[$r->age_group_id] ?? null,
                    'sequence' => $r->sequence,
                    'condition' => $r->condition,
                    'color_key' => $r->result_color?->key,
                    'message_key' => $messageKeyById[$r->global_result_message_id] ?? null,
                    'source' => $r->source,
                ])
                ->sortBy([['group_code', 'asc'], ['sequence', 'asc']])
                ->values()
                ->all(),
            'global_result_messages' => $messages
                ->map(fn (GlobalResultMessage $m) => [
                    'key' => $m->key,
                    'color_key' => $m->result_color?->key,
                    'headline' => $m->headline,
                    'referral_items' => $m->referral_items,
                    'sort_order' => $m->sort_order,
                ])
                ->sortBy('key')
                ->values()
                ->all(),
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
