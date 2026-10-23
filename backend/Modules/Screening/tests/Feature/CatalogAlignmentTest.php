<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Screening\Models\ConfirmatoryTestResult;
use Modules\Screening\Models\FindingType;
use Modules\Screening\Models\ScreeningSituation;
use Modules\Screening\Models\ScreeningStatus;
use Modules\Screening\Services\Scoring\Values\ScoringFinding;
use Modules\Screening\Services\Scoring\Values\SituationKey;
use Tests\TestCase;

/**
 * REQ-SCR-001 — drift tripwire in BOTH directions: screening_situation.key
 * must equal the 7 SituationKey constants and finding_type.key must equal
 * the 5 ScoringFinding keys, exactly.
 */
final class CatalogAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_screening_situation_keys_equal_engine_situation_key_constants_both_directions(): void
    {
        (new CatalogSeeder)->run();

        $engineKeys = [
            SituationKey::FIRST_TIME_GREEN,
            SituationKey::FIRST_TIME_YELLOW,
            SituationKey::FIRST_TIME_RED,
            SituationKey::RECOVERED_FROM_YELLOW,
            SituationKey::RECOVERED_FROM_RED,
            SituationKey::UNCHANGED,
            SituationKey::WORSENED,
        ];
        sort($engineKeys);

        $dbKeys = ScreeningSituation::query()->pluck('key')->sort()->values()->all();

        $this->assertSame(7, count($dbKeys));
        $this->assertSame($engineKeys, $dbKeys);
    }

    public function test_screening_situation_sis_code_mapping_is_exact(): void
    {
        (new CatalogSeeder)->run();

        $sisCodeByKey = ScreeningSituation::query()->pluck('sis_code', 'key')->all();

        $this->assertSame([
            SituationKey::FIRST_TIME_GREEN => 1,
            SituationKey::FIRST_TIME_YELLOW => 2,
            SituationKey::FIRST_TIME_RED => 3,
            SituationKey::RECOVERED_FROM_YELLOW => 4,
            SituationKey::RECOVERED_FROM_RED => 5,
            SituationKey::UNCHANGED => 6,
            SituationKey::WORSENED => 6,
        ], $sisCodeByKey);
    }

    public function test_finding_type_keys_equal_engine_scoring_finding_constants_both_directions(): void
    {
        (new CatalogSeeder)->run();

        $engineKeys = [
            ScoringFinding::AGE_GROUP_SUBSTITUTED_BY_CORRECTED_AGE,
            ScoringFinding::UNKNOWN_PERINATAL_INPUT,
            ScoringFinding::AUTO_ANSWER_OVERRODE_MANUAL,
            ScoringFinding::UNANSWERED_ITEM,
            ScoringFinding::MISSING_PERCENTILE_REFERENCE,
        ];
        sort($engineKeys);

        $dbKeys = FindingType::query()->pluck('key')->sort()->values()->all();

        $this->assertSame(5, count($dbKeys));
        $this->assertSame($engineKeys, $dbKeys);
    }

    public function test_screening_status_and_confirmatory_test_result_are_seeded_with_fixed_uuids(): void
    {
        (new CatalogSeeder)->run();

        $this->assertSame('Borrador', ScreeningStatus::find(ScreeningStatus::DRAFT)?->name);
        $this->assertSame(4, ScreeningStatus::count());

        $this->assertSame('Retraso confirmado', ConfirmatoryTestResult::find(ConfirmatoryTestResult::DELAY_CONFIRMED)?->name);
        $this->assertSame(3, ConfirmatoryTestResult::count());
    }

    public function test_reseeding_screening_catalogs_is_idempotent(): void
    {
        (new CatalogSeeder)->run();
        (new CatalogSeeder)->run();

        $this->assertSame(4, ScreeningStatus::count());
        $this->assertSame(7, ScreeningSituation::count());
        $this->assertSame(5, FindingType::count());
        $this->assertSame(3, ConfirmatoryTestResult::count());
    }
}
