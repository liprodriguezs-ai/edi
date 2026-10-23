<?php

namespace Modules\Instrument\Services;

use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\AreaScoringRule;
use Modules\Instrument\Models\DevelopmentArea;
use Modules\Instrument\Models\GlobalResultMessage;
use Modules\Instrument\Models\GlobalScoringRule;
use Modules\Instrument\Models\HeadCircumferenceReference;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\ResultColor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Instrument\Models\ScreeningItemOption;

/**
 * Runs checks V1-V10 (design §7) against a version. Shared by
 * `instrument:verify` and the seeder's publish step (design D21). Returns a
 * flat list of human-readable failure strings; empty = pass.
 */
class InstrumentVerificationService
{
    private const GRAMMAR_OPERATORS = ['all', 'any', 'not', 'count', 'sum', 'color_in'];

    private const GRAMMAR_SETS = ['red_areas', 'yellow_areas', 'green_areas', 'alert_signs', 'risk_factors'];

    private const GRAMMAR_COLOR_KEYS = ['red', 'yellow', 'green'];

    public function __construct(private readonly InstrumentContentHasher $hasher) {}

    /**
     * @return array<int, string>
     */
    public function verify(InstrumentVersion $version): array
    {
        $failures = [];
        $failures = [...$failures, ...$this->checkOfficialRanges($version)];
        $failures = [...$failures, ...$this->checkAlternatePointers($version)];
        $failures = [...$failures, ...$this->checkItemOptions($version)];
        $failures = [...$failures, ...$this->checkAreaRuleCoverage($version)];
        $failures = [...$failures, ...$this->checkAlternateCoverage($version)];
        $failures = [...$failures, ...$this->checkGlobalRules($version)];
        $failures = [...$failures, ...$this->checkMessages($version)];
        $failures = [...$failures, ...$this->checkVersionConsistency($version)];
        $failures = [...$failures, ...$this->checkPercentiles($version)];

        if ($version->isPublished()) {
            $failures = [...$failures, ...$this->checkContentHash($version)];
        }

        return $failures;
    }

    // ── V1: official ranges contiguous/gap-free 30-2159; alternates NULL ──

    private function checkOfficialRanges(InstrumentVersion $version): array
    {
        $failures = [];

        $official = AgeGroup::query()
            ->where('instrument_version_id', $version->id)
            ->where('is_alternate', false)
            ->get();

        foreach ($official as $g) {
            if ($g->min_age_days === null || $g->max_age_days === null) {
                $failures[] = "V1: official group {$g->code} has a NULL age range";
            }
        }

        $sorted = $official->whereNotNull('min_age_days')->sortBy('min_age_days')->values();

        if ($sorted->isNotEmpty()) {
            if ((int) $sorted->first()->min_age_days !== 30) {
                $failures[] = "V1: first official group ({$sorted->first()->code}) min_age_days is {$sorted->first()->min_age_days}, expected 30";
            }
            if ((int) $sorted->last()->max_age_days !== 2159) {
                $failures[] = "V1: last official group ({$sorted->last()->code}) max_age_days is {$sorted->last()->max_age_days}, expected 2159";
            }
            for ($i = 1; $i < $sorted->count(); $i++) {
                $prev = $sorted[$i - 1];
                $cur = $sorted[$i];
                if ((int) $cur->min_age_days !== (int) $prev->max_age_days + 1) {
                    $failures[] = "V1: range gap/overlap between {$prev->code} (max {$prev->max_age_days}) and {$cur->code} (min {$cur->min_age_days})";
                }
            }
        }

        $alternates = AgeGroup::query()
            ->where('instrument_version_id', $version->id)
            ->where('is_alternate', true)
            ->get();

        foreach ($alternates as $a) {
            if ($a->min_age_days !== null || $a->max_age_days !== null) {
                $failures[] = "V1: alternate group {$a->code} must have a NULL age range";
            }
        }

        return $failures;
    }

    // ── V2: alternate pointers ─────────────────────────────────────────────

    private function checkAlternatePointers(InstrumentVersion $version): array
    {
        $failures = [];

        $official = AgeGroup::query()->where('instrument_version_id', $version->id)->where('is_alternate', false)->get();
        $alternates = AgeGroup::query()->where('instrument_version_id', $version->id)->where('is_alternate', true)->get()->keyBy('id');

        $referenced = [];

        foreach ($official as $g) {
            if ($g->code === 'G1') {
                if ($g->alternate_age_group_id !== null) {
                    $failures[] = 'V2: G1 must not point to an alternate';
                }

                continue;
            }

            if ($g->alternate_age_group_id === null) {
                $failures[] = "V2: {$g->code} has no alternate_age_group_id";

                continue;
            }

            if (! $alternates->has($g->alternate_age_group_id)) {
                $failures[] = "V2: {$g->code} points to a row that is not an alternate group of this version";

                continue;
            }

            $referenced[$g->alternate_age_group_id] = ($referenced[$g->alternate_age_group_id] ?? 0) + 1;
        }

        foreach ($referenced as $id => $count) {
            if ($count > 1) {
                $failures[] = "V2: alternate {$alternates[$id]->code} is referenced by {$count} official groups";
            }
        }

        foreach ($alternates as $id => $a) {
            if (! isset($referenced[$id])) {
                $failures[] = "V2: alternate {$a->code} is never referenced by an official group";
            }
            if ($a->alternate_age_group_id !== null) {
                $failures[] = "V2: alternate {$a->code} must not itself point to another group";
            }
        }

        return $failures;
    }

    // ── V3: every item has exactly 2 options, distinct colors ─────────────

    private function checkItemOptions(InstrumentVersion $version): array
    {
        $failures = [];

        $items = ScreeningItem::query()
            ->where('instrument_version_id', $version->id)
            ->with('options', 'age_group:id,code')
            ->get();

        foreach ($items as $item) {
            $opts = $item->options;
            $label = "{$item->age_group?->code}/{$item->code}";

            if ($opts->count() !== 2) {
                $failures[] = "V3: item {$label} has {$opts->count()} options, expected 2";

                continue;
            }

            $sortOrders = $opts->pluck('sort_order')->sort()->values()->all();
            if ($sortOrders !== [1, 2]) {
                $failures[] = "V3: item {$label} option sort_order values are [".implode(',', $sortOrders).'], expected [1,2]';
            }

            if ($opts->pluck('result_color_id')->unique()->count() !== 2) {
                $failures[] = "V3: item {$label} options share the same result_color_id";
            }
        }

        return $failures;
    }

    // ── V4: area rule coverage + item_count exactness ─────────────────────

    private function checkAreaRuleCoverage(InstrumentVersion $version): array
    {
        $failures = [];

        $itemCounts = ScreeningItem::query()
            ->where('instrument_version_id', $version->id)
            ->selectRaw('age_group_id, development_area_id, count(*) as cnt')
            ->groupBy('age_group_id', 'development_area_id')
            ->get()
            ->keyBy(fn ($r) => $r->age_group_id.'|'.$r->development_area_id);

        $areas = DevelopmentArea::query()->get()->keyBy('id');
        $groups = AgeGroup::query()->where('instrument_version_id', $version->id)->get()->keyBy('id');
        $rules = AreaScoringRule::query()
            ->where('instrument_version_id', $version->id)
            ->get()
            ->keyBy(fn ($r) => $r->age_group_id.'|'.$r->development_area_id);

        foreach ($itemCounts as $key => $row) {
            $area = $areas[$row->development_area_id] ?? null;
            $group = $groups[$row->age_group_id] ?? null;
            $label = ($group->code ?? '?').'/'.($area->code ?? '?');

            if ($area?->scoring_kind === DevelopmentArea::SCORING_KIND_COUNTER) {
                if ($rules->has($key)) {
                    $failures[] = "V4: counter area {$label} must not have an area_scoring_rule row";
                }

                continue;
            }

            if (! $rules->has($key)) {
                $failures[] = "V4: missing area_scoring_rule for {$label} ({$row->cnt} items)";

                continue;
            }

            $rule = $rules[$key];
            if ((int) $rule->item_count !== (int) $row->cnt) {
                $failures[] = "V4: {$label} item_count is {$rule->item_count}, real item count is {$row->cnt}";
            }
            if ($rule->red_threshold < 1) {
                $failures[] = "V4: {$label} red_threshold must be >= 1";
            }
            if ($rule->green_threshold !== null && $rule->green_threshold < 0) {
                $failures[] = "V4: {$label} green_threshold must be NULL or >= 0";
            }
        }

        foreach ($rules as $key => $rule) {
            if (! $itemCounts->has($key)) {
                $group = $groups[$rule->age_group_id] ?? null;
                $area = $areas[$rule->development_area_id] ?? null;
                $failures[] = 'V4: area_scoring_rule exists for '.($group->code ?? '?').'/'.($area->code ?? '?').' but that pair has no items';
            }
        }

        return $failures;
    }

    // ── V5: alternate coverage of development axes ────────────────────────

    private function checkAlternateCoverage(InstrumentVersion $version): array
    {
        $failures = [];

        $axisIds = DevelopmentArea::query()->where('is_development_axis', true)->pluck('id')->all();

        $groups = AgeGroup::query()
            ->where('instrument_version_id', $version->id)
            ->where('is_alternate', false)
            ->whereNotNull('alternate_age_group_id')
            ->get();

        foreach ($groups as $g) {
            $ownAxes = ScreeningItem::query()
                ->where('age_group_id', $g->id)
                ->whereIn('development_area_id', $axisIds)
                ->distinct()
                ->pluck('development_area_id')
                ->sort()
                ->values()
                ->all();

            $altAxes = ScreeningItem::query()
                ->where('age_group_id', $g->alternate_age_group_id)
                ->whereIn('development_area_id', $axisIds)
                ->distinct()
                ->pluck('development_area_id')
                ->sort()
                ->values()
                ->all();

            if ($ownAxes !== $altAxes) {
                $failures[] = "V5: {$g->code} development-axis coverage differs from its alternate group";
            }
        }

        return $failures;
    }

    // ── V6: global rules + closed grammar validation ──────────────────────

    private function checkGlobalRules(InstrumentVersion $version): array
    {
        $failures = [];

        $groups = AgeGroup::query()->where('instrument_version_id', $version->id)->get()->keyBy('id');
        $areaCodeById = DevelopmentArea::query()->pluck('code', 'id');
        $colorKeyById = ResultColor::query()->pluck('key', 'id');

        $rulesByGroup = GlobalScoringRule::query()
            ->where('instrument_version_id', $version->id)
            ->orderBy('sequence')
            ->get()
            ->groupBy('age_group_id');

        $itemAreaCodesByGroup = ScreeningItem::query()
            ->where('instrument_version_id', $version->id)
            ->get()
            ->groupBy('age_group_id')
            ->map(fn ($items) => $items->pluck('development_area_id')->unique()
                ->map(fn ($id) => $areaCodeById[$id] ?? null)->filter()->values()->all());

        foreach ($groups as $gid => $group) {
            $rules = $rulesByGroup->get($gid, collect());

            if ($group->is_alternate) {
                if ($rules->isNotEmpty()) {
                    $failures[] = "V6: alternate group {$group->code} must have zero global_scoring_rule rows";
                }

                continue;
            }

            if ($rules->isEmpty()) {
                $failures[] = "V6: official group {$group->code} has no global_scoring_rule rows";

                continue;
            }

            if ($rules->pluck('sequence')->count() !== $rules->pluck('sequence')->unique()->count()) {
                $failures[] = "V6: {$group->code} has duplicate sequence values";
            }

            $last = $rules->sortBy('sequence')->last();
            if ($last->condition !== ['all' => []]) {
                $failures[] = "V6: {$group->code} last rule (sequence {$last->sequence}) must be the catch-all {\"all\":[]}";
            } elseif (($colorKeyById[$last->result_color_id] ?? null) !== 'green') {
                $failures[] = "V6: {$group->code} catch-all rule must resolve to green";
            }

            $groupAreaCodes = $itemAreaCodesByGroup->get($gid, []);

            foreach ($rules as $rule) {
                foreach ($this->validateCondition($rule->condition, $groupAreaCodes) as $error) {
                    $failures[] = "V6: {$group->code} sequence {$rule->sequence}: {$error}";
                }
            }
        }

        return $failures;
    }

    /**
     * @param  array<int, string>  $groupAreaCodes
     * @return array<int, string>
     */
    private function validateCondition(mixed $condition, array $groupAreaCodes): array
    {
        if (! is_array($condition) || count($condition) !== 1) {
            return ['condition must be an object with exactly one operator key: '.json_encode($condition)];
        }

        $operator = array_key_first($condition);
        $value = $condition[$operator];

        if (! in_array($operator, self::GRAMMAR_OPERATORS, true)) {
            return ["unknown operator '{$operator}'"];
        }

        $errors = [];

        switch ($operator) {
            case 'all':
            case 'any':
                if (! is_array($value)) {
                    $errors[] = "'{$operator}' must be an array of conditions";
                    break;
                }
                foreach ($value as $sub) {
                    $errors = [...$errors, ...$this->validateCondition($sub, $groupAreaCodes)];
                }
                break;

            case 'not':
                $errors = [...$errors, ...$this->validateCondition($value, $groupAreaCodes)];
                break;

            case 'count':
                if (! is_array($value) || ! isset($value['of'])) {
                    $errors[] = "'count' requires an 'of' key";
                    break;
                }
                if (! in_array($value['of'], self::GRAMMAR_SETS, true)) {
                    $errors[] = "'count.of' has an unknown SET '{$value['of']}'";
                }
                if (count(array_intersect(array_keys($value), ['gte', 'eq', 'lte'])) !== 1) {
                    $errors[] = "'count' requires exactly one of gte|eq|lte";
                }
                break;

            case 'sum':
                if (! is_array($value) || ! isset($value['of']) || ! is_array($value['of'])) {
                    $errors[] = "'sum' requires an 'of' array";
                    break;
                }
                foreach ($value['of'] as $set) {
                    if (! in_array($set, self::GRAMMAR_SETS, true)) {
                        $errors[] = "'sum.of' has an unknown SET '{$set}'";
                    }
                }
                if (! isset($value['gte'])) {
                    $errors[] = "'sum' requires a 'gte' key";
                }
                break;

            case 'color_in':
                if (! is_array($value) || ! isset($value['areas'], $value['is']) || ! is_array($value['areas'])) {
                    $errors[] = "'color_in' requires 'areas' (array) and 'is'";
                    break;
                }
                foreach ($value['areas'] as $area) {
                    if (! in_array($area, $groupAreaCodes, true)) {
                        $errors[] = "'color_in' area '{$area}' has no items in this group";
                    }
                }
                if (! in_array($value['is'], self::GRAMMAR_COLOR_KEYS, true)) {
                    $errors[] = "'color_in.is' has an unknown color key '{$value['is']}'";
                }
                break;
        }

        return $errors;
    }

    // ── V7: message coherence ──────────────────────────────────────────────

    private function checkMessages(InstrumentVersion $version): array
    {
        $failures = [];

        $messages = GlobalResultMessage::query()->where('instrument_version_id', $version->id)->get()->keyBy('key');

        foreach ([
            GlobalResultMessage::KEY_RED,
            GlobalResultMessage::KEY_YELLOW_DEVELOPMENT,
            GlobalResultMessage::KEY_YELLOW_SOCIAL,
            GlobalResultMessage::KEY_GREEN,
        ] as $key) {
            if (! $messages->has($key)) {
                $failures[] = "V7: missing global_result_message with key '{$key}'";
            }
        }

        $messagesById = GlobalResultMessage::query()->where('instrument_version_id', $version->id)->get()->keyBy('id');
        $rules = GlobalScoringRule::query()->where('instrument_version_id', $version->id)->with('age_group:id,code')->get();

        foreach ($rules as $rule) {
            $label = "{$rule->age_group?->code} sequence {$rule->sequence}";
            $message = $messagesById[$rule->global_result_message_id] ?? null;

            if ($message === null) {
                $failures[] = "V7: rule ({$label}) references a message outside this version";

                continue;
            }

            if ($message->result_color_id !== $rule->result_color_id) {
                $failures[] = "V7: rule ({$label}) color does not match its message's color";
            }
        }

        return $failures;
    }

    // ── V8: instrument_version_id matches the parent chain ────────────────

    private function checkVersionConsistency(InstrumentVersion $version): array
    {
        $failures = [];

        $groupVersionById = AgeGroup::query()->pluck('instrument_version_id', 'id');
        $itemVersionById = ScreeningItem::query()->pluck('instrument_version_id', 'id');
        $messageVersionById = GlobalResultMessage::query()->pluck('instrument_version_id', 'id');

        foreach (ScreeningItem::query()->where('instrument_version_id', $version->id)->get() as $item) {
            if (($groupVersionById[$item->age_group_id] ?? null) !== $item->instrument_version_id) {
                $failures[] = "V8: screening_item {$item->code} instrument_version_id does not match its age_group";
            }
        }

        foreach (ScreeningItemOption::query()->where('instrument_version_id', $version->id)->get() as $option) {
            if (($itemVersionById[$option->screening_item_id] ?? null) !== $option->instrument_version_id) {
                $failures[] = "V8: screening_item_option {$option->id} instrument_version_id does not match its item";
            }
        }

        foreach (AreaScoringRule::query()->where('instrument_version_id', $version->id)->get() as $rule) {
            if (($groupVersionById[$rule->age_group_id] ?? null) !== $rule->instrument_version_id) {
                $failures[] = "V8: area_scoring_rule {$rule->id} instrument_version_id does not match its age_group";
            }
        }

        foreach (GlobalScoringRule::query()->where('instrument_version_id', $version->id)->get() as $rule) {
            if (($groupVersionById[$rule->age_group_id] ?? null) !== $rule->instrument_version_id) {
                $failures[] = "V8: global_scoring_rule {$rule->id} instrument_version_id does not match its age_group";
            }
            if (($messageVersionById[$rule->global_result_message_id] ?? null) !== $rule->instrument_version_id) {
                $failures[] = "V8: global_scoring_rule {$rule->id} message belongs to a different version";
            }
        }

        return $failures;
    }

    // ── V9: percentile invariants ───────────────────────────────────────────

    private function checkPercentiles(InstrumentVersion $version): array
    {
        $failures = [];

        $refs = HeadCircumferenceReference::query()->where('instrument_version_id', $version->id)->get();

        foreach ($refs->groupBy('sex_id') as $sexId => $rows) {
            $months = $rows->pluck('age_months')->sort()->values();

            if ($months->count() !== $months->unique()->count()) {
                $failures[] = "V9: duplicate (sex, month) rows for sex {$sexId}";
            }

            if ($months->isNotEmpty()) {
                $expected = range((int) $months->first(), (int) $months->last());
                if ($months->all() !== $expected) {
                    $failures[] = "V9: month coverage has a hole for sex {$sexId}";
                }
            }

            foreach ($rows as $r) {
                if ($r->min_circumference_mm >= $r->max_circumference_mm) {
                    $failures[] = "V9: month {$r->age_months} sex {$sexId}: min_circumference_mm must be < max_circumference_mm";
                }
                if ($r->min_circumference_mm <= 0 || $r->max_circumference_mm <= 0) {
                    $failures[] = "V9: month {$r->age_months} sex {$sexId}: values must be > 0";
                }
            }
        }

        return $failures;
    }

    // ── V10: published content_hash reproducibility ────────────────────────

    private function checkContentHash(InstrumentVersion $version): array
    {
        $recomputed = $this->hasher->hash($version);

        if ($recomputed !== $version->content_hash) {
            return ["V10: recomputed content_hash ({$recomputed}) does not match stored content_hash ({$version->content_hash})"];
        }

        return [];
    }
}
