<?php

namespace Modules\Instrument\Services;

use Modules\ChildRecord\Models\Sex;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\ApplicationPosition;
use Modules\Instrument\Models\AreaScoringRule;
use Modules\Instrument\Models\DevelopmentArea;
use Modules\Instrument\Models\GlobalResultMessage;
use Modules\Instrument\Models\GlobalScoringRule;
use Modules\Instrument\Models\HeadCircumferenceReference;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\ResultColor;
use Modules\Instrument\Models\RiskFactor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Instrument\Models\ScreeningItemOption;
use Modules\Instrument\Support\InstrumentContentUuid;
use RuntimeException;

/**
 * Loads a version's committed per-version CSV directory (design §6) into a
 * DRAFT instrument_version. Ids are deterministic UUIDv5s (D6) derived from
 * the version key, table, and natural key (design §4) -- re-running against
 * the same CSVs always produces the same rows.
 *
 * Load order matters: age_group before screening_item (FK), screening_item
 * before screening_item_option (FK), global_result_message before
 * global_scoring_rule (FK).
 */
class InstrumentCsvLoader
{
    /** @var array<string,string> code => id */
    private array $developmentAreaByCode;

    /** @var array<string,string> key => id */
    private array $applicationPositionByKey;

    /** @var array<string,string> key => id */
    private array $resultColorByKey;

    /** @var array<string,string> key => id */
    private array $sexByKey;

    /** @var array<string,string> code => id, filled while loading age_group */
    private array $ageGroupIdByCode = [];

    /** @var array<string,string> "{group_code}/{code}" => id, filled while loading screening_item */
    private array $screeningItemIdByKey = [];

    /** @var array<string,string> key => id, filled while loading global_result_message */
    private array $globalResultMessageIdByKey = [];

    public function __construct()
    {
        $this->developmentAreaByCode = DevelopmentArea::query()->pluck('id', 'code')->all();
        $this->applicationPositionByKey = ApplicationPosition::query()->pluck('id', 'key')->all();
        $this->resultColorByKey = ResultColor::query()->pluck('id', 'key')->all();
        $this->sexByKey = Sex::query()->pluck('id', 'key')->all();
    }

    public function load(InstrumentVersion $version, string $csvDir): void
    {
        if ($version->isPublished()) {
            throw new RuntimeException("Cannot load CSVs into published version '{$version->key}'.");
        }

        $this->loadAgeGroups($version, $csvDir.'/age_group.csv');
        $this->loadScreeningItems($version, $csvDir.'/screening_item.csv');
        $this->loadRiskFactors($version, $csvDir.'/risk_factor.csv');
        $this->loadHeadCircumferenceReferences($version, $csvDir.'/head_circumference_reference.csv');
        $this->loadGlobalResultMessages($version, $csvDir.'/global_result_message.csv');
        $this->loadAreaScoringRules($version, $csvDir.'/area_scoring_rule.csv');
        $this->loadGlobalScoringRules($version, $csvDir.'/global_scoring_rule.csv');
    }

    // ── age_group ────────────────────────────────────────────────────────

    private function loadAgeGroups(InstrumentVersion $version, string $path): void
    {
        $rows = self::readCsv($path);

        // Pass 1: create every group (alternate_age_group_id resolved in pass 2).
        foreach ($rows as $row) {
            $id = InstrumentContentUuid::for($version->key, 'age_group', $row['code']);
            $this->ageGroupIdByCode[$row['code']] = $id;

            AgeGroup::query()->create([
                'id' => $id,
                'instrument_version_id' => $version->id,
                'code' => $row['code'],
                'name' => $row['name'],
                'ordinal' => (int) $row['ordinal'],
                'is_alternate' => $row['is_alternate'] === '1',
                'min_age_days' => self::nullableInt($row['min_age_days']),
                'max_age_days' => self::nullableInt($row['max_age_days']),
                'min_age_months' => self::nullableInt($row['min_age_months']),
                'max_age_months' => self::nullableInt($row['max_age_months']),
                'alternate_age_group_id' => null,
                'materials_note' => $row['materials_note'] !== '' ? $row['materials_note'] : null,
                'legacy_id' => self::nullableInt($row['legacy_id']),
            ]);
        }

        // Pass 2: wire alternate_age_group_id now that every code is known.
        foreach ($rows as $row) {
            if ($row['alternate_group_code'] === '') {
                continue;
            }

            AgeGroup::query()
                ->whereKey($this->ageGroupIdByCode[$row['code']])
                ->update(['alternate_age_group_id' => $this->ageGroupIdByCode[$row['alternate_group_code']]]);
        }
    }

    // ── screening_item (+ 2 embedded options) ───────────────────────────

    private function loadScreeningItems(InstrumentVersion $version, string $path): void
    {
        foreach (self::readCsv($path) as $row) {
            $groupId = $this->ageGroupIdByCode[$row['group_code']]
                ?? throw new RuntimeException("Unknown age_group code '{$row['group_code']}' in screening_item.csv");
            $areaId = $this->developmentAreaByCode[$row['area_code']]
                ?? throw new RuntimeException("Unknown development_area code '{$row['area_code']}' in screening_item.csv");
            $positionId = $this->applicationPositionByKey[$row['position_key']]
                ?? throw new RuntimeException("Unknown application_position key '{$row['position_key']}' in screening_item.csv");

            $naturalKey = "{$row['group_code']}/{$row['code']}";
            $itemId = InstrumentContentUuid::for($version->key, 'screening_item', $naturalKey);
            $this->screeningItemIdByKey[$naturalKey] = $itemId;

            ScreeningItem::query()->create([
                'id' => $itemId,
                'instrument_version_id' => $version->id,
                'age_group_id' => $groupId,
                'development_area_id' => $areaId,
                'application_position_id' => $positionId,
                'code' => $row['code'],
                'question' => $row['question'],
                'sort_order' => (int) $row['sort_order'],
                'legacy_id' => self::nullableInt($row['legacy_id']),
            ]);

            foreach ([1, 2] as $n) {
                $label = $row["option_{$n}_label"];
                $colorKey = $row["option_{$n}_color"];
                $colorId = $this->resultColorByKey[$colorKey]
                    ?? throw new RuntimeException("Unknown result_color key '{$colorKey}' in screening_item.csv");

                ScreeningItemOption::query()->create([
                    'id' => InstrumentContentUuid::for($version->key, 'screening_item_option', "{$naturalKey}/{$label}"),
                    'instrument_version_id' => $version->id,
                    'screening_item_id' => $itemId,
                    'label' => $label,
                    'sort_order' => $n,
                    'result_color_id' => $colorId,
                ]);
            }
        }
    }

    // ── risk_factor ──────────────────────────────────────────────────────

    private function loadRiskFactors(InstrumentVersion $version, string $path): void
    {
        foreach (self::readCsv($path) as $row) {
            RiskFactor::query()->create([
                'id' => InstrumentContentUuid::for($version->key, 'risk_factor', $row['code']),
                'instrument_version_id' => $version->id,
                'code' => $row['code'],
                'question' => $row['question'],
                'sort_order' => (int) $row['sort_order'],
                'auto_derivation_key' => $row['auto_derivation_key'] !== '' ? $row['auto_derivation_key'] : null,
                'legacy_id' => self::nullableInt($row['legacy_id']),
            ]);
        }
    }

    // ── head_circumference_reference ────────────────────────────────────

    private function loadHeadCircumferenceReferences(InstrumentVersion $version, string $path): void
    {
        foreach (self::readCsv($path) as $row) {
            $sexId = $this->sexByKey[$row['sex_key']]
                ?? throw new RuntimeException("Unknown sex key '{$row['sex_key']}' in head_circumference_reference.csv");

            HeadCircumferenceReference::query()->create([
                'id' => InstrumentContentUuid::for($version->key, 'head_circumference_reference', "{$row['sex_key']}/{$row['age_months']}"),
                'instrument_version_id' => $version->id,
                'sex_id' => $sexId,
                'age_months' => (int) $row['age_months'],
                'min_circumference_mm' => (int) $row['min_mm'],
                'max_circumference_mm' => (int) $row['max_mm'],
                'legacy_id' => self::nullableInt($row['legacy_id']),
            ]);
        }
    }

    // ── global_result_message ───────────────────────────────────────────

    private function loadGlobalResultMessages(InstrumentVersion $version, string $path): void
    {
        foreach (self::readCsv($path) as $row) {
            $colorId = $this->resultColorByKey[$row['result_color_key']]
                ?? throw new RuntimeException("Unknown result_color key '{$row['result_color_key']}' in global_result_message.csv");

            $id = InstrumentContentUuid::for($version->key, 'global_result_message', $row['key']);
            $this->globalResultMessageIdByKey[$row['key']] = $id;

            GlobalResultMessage::query()->create([
                'id' => $id,
                'instrument_version_id' => $version->id,
                'key' => $row['key'],
                'result_color_id' => $colorId,
                'headline' => $row['headline'],
                'referral_items' => json_decode($row['referral_items'], true, 512, JSON_THROW_ON_ERROR),
                'sort_order' => (int) $row['sort_order'],
            ]);
        }
    }

    // ── area_scoring_rule ────────────────────────────────────────────────

    private function loadAreaScoringRules(InstrumentVersion $version, string $path): void
    {
        foreach (self::readCsv($path) as $row) {
            $groupId = $this->ageGroupIdByCode[$row['group_code']]
                ?? throw new RuntimeException("Unknown age_group code '{$row['group_code']}' in area_scoring_rule.csv");
            $areaId = $this->developmentAreaByCode[$row['area_code']]
                ?? throw new RuntimeException("Unknown development_area code '{$row['area_code']}' in area_scoring_rule.csv");

            AreaScoringRule::query()->create([
                'id' => InstrumentContentUuid::for($version->key, 'area_scoring_rule', "{$row['group_code']}/{$row['area_code']}"),
                'instrument_version_id' => $version->id,
                'age_group_id' => $groupId,
                'development_area_id' => $areaId,
                'item_count' => (int) $row['item_count'],
                'red_threshold' => (int) $row['red_threshold'],
                'green_threshold' => self::nullableInt($row['green_threshold']),
                'allows_yellow' => $row['allows_yellow'] === '1',
                'source' => $row['source'],
                'notes' => $row['notes'] !== '' ? $row['notes'] : null,
            ]);
        }
    }

    // ── global_scoring_rule ──────────────────────────────────────────────

    private function loadGlobalScoringRules(InstrumentVersion $version, string $path): void
    {
        foreach (self::readCsv($path) as $row) {
            $groupId = $this->ageGroupIdByCode[$row['group_code']]
                ?? throw new RuntimeException("Unknown age_group code '{$row['group_code']}' in global_scoring_rule.csv");
            $colorId = $this->resultColorByKey[$row['result_color_key']]
                ?? throw new RuntimeException("Unknown result_color key '{$row['result_color_key']}' in global_scoring_rule.csv");
            $messageId = $this->globalResultMessageIdByKey[$row['message_key']]
                ?? throw new RuntimeException("Unknown global_result_message key '{$row['message_key']}' in global_scoring_rule.csv");

            GlobalScoringRule::query()->create([
                'id' => InstrumentContentUuid::for($version->key, 'global_scoring_rule', "{$row['group_code']}/{$row['sequence']}"),
                'instrument_version_id' => $version->id,
                'age_group_id' => $groupId,
                'sequence' => (int) $row['sequence'],
                'condition' => json_decode($row['condition'], true, 512, JSON_THROW_ON_ERROR),
                'result_color_id' => $colorId,
                'global_result_message_id' => $messageId,
                'source' => $row['source'],
                'notes' => $row['notes'] !== '' ? $row['notes'] : null,
            ]);
        }
    }

    // ── CSV reading (fopen/fgetcsv -- correctly handles embedded newlines
    //    inside quoted fields, unlike file()+str_getcsv used elsewhere in
    //    this codebase for single-line CSVs) ─────────────────────────────

    /**
     * @return array<int, array<string, string>>
     */
    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return [];
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($header)) {
                continue;
            }
            $rows[] = array_combine($header, $row);
        }

        fclose($handle);

        return $rows;
    }

    private static function nullableInt(string $value): ?int
    {
        return $value === '' ? null : (int) $value;
    }
}
