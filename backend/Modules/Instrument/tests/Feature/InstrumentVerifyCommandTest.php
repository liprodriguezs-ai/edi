<?php

declare(strict_types=1);

namespace Modules\Instrument\Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\ChildRecord\Models\Sex;
use Modules\Instrument\Database\Seeders\InstrumentSeeder;
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
use Tests\TestCase;

/**
 * design §7 / REQ-INS-013 (task 4.3). `instrument:verify` is the CI gate:
 * both real seeded editions must exit 0, and every V-check must be provably
 * able to fail on a purpose-built draft fixture. Fixtures below isolate ONE
 * defect each; other checks MAY also fire on the same minimal fixture (a
 * real 451-item bank isn't rebuilt here) -- each test asserts its own
 * check's message is PRESENT among the failures, not that it's the only one.
 */
final class InstrumentVerifyCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
    }

    public function test_command_exits_zero_for_both_seeded_editions(): void
    {
        (new InstrumentSeeder)->run();

        $this->assertSame(0, Artisan::call('instrument:verify', ['version' => 'edi-legacy-2013']));
        $this->assertSame(0, Artisan::call('instrument:verify', ['version' => 'edi-2022-2ed']));
    }

    public function test_unknown_version_key_fails(): void
    {
        $this->assertSame(1, Artisan::call('instrument:verify', ['version' => 'does-not-exist']));
    }

    public function test_range_gap_is_caught(): void
    {
        $version = InstrumentVersion::factory()->create();
        AgeGroup::factory()->create([
            'instrument_version_id' => $version->id, 'code' => 'G1', 'ordinal' => 1,
            'is_alternate' => false, 'min_age_days' => 30, 'max_age_days' => 59,
        ]);
        AgeGroup::factory()->create([
            'instrument_version_id' => $version->id, 'code' => 'G2', 'ordinal' => 2,
            'is_alternate' => false, 'min_age_days' => 61, 'max_age_days' => 2159,
        ]);

        Artisan::call('instrument:verify', ['version' => $version->key]);
        $output = Artisan::output();
        $this->assertStringContainsString('V1', $output);
        $this->assertStringContainsString('gap', $output);
    }

    public function test_missing_option_is_caught(): void
    {
        $version = InstrumentVersion::factory()->create();
        $group = AgeGroup::factory()->create(['instrument_version_id' => $version->id]);
        $item = ScreeningItem::factory()->create([
            'instrument_version_id' => $version->id, 'age_group_id' => $group->id,
        ]);
        ScreeningItemOption::factory()->create([
            'instrument_version_id' => $version->id, 'screening_item_id' => $item->id,
            'label' => 'NO', 'sort_order' => 1, 'result_color_id' => ResultColor::RED,
        ]);
        // Only 1 option instead of 2.

        Artisan::call('instrument:verify', ['version' => $version->key]);
        $this->assertStringContainsString('V3', Artisan::output());
    }

    public function test_wrong_item_count_is_caught(): void
    {
        $version = InstrumentVersion::factory()->create();
        $group = AgeGroup::factory()->create(['instrument_version_id' => $version->id]);
        $item = ScreeningItem::factory()->create([
            'instrument_version_id' => $version->id, 'age_group_id' => $group->id, 'development_area_id' => DevelopmentArea::MG,
        ]);
        ScreeningItemOption::factory()->create(['instrument_version_id' => $version->id, 'screening_item_id' => $item->id, 'label' => 'NO', 'sort_order' => 1, 'result_color_id' => ResultColor::RED]);
        ScreeningItemOption::factory()->create(['instrument_version_id' => $version->id, 'screening_item_id' => $item->id, 'label' => 'SI', 'sort_order' => 2, 'result_color_id' => ResultColor::GREEN]);

        AreaScoringRule::factory()->create([
            'instrument_version_id' => $version->id, 'age_group_id' => $group->id,
            'development_area_id' => DevelopmentArea::MG, 'item_count' => 5,
        ]);

        Artisan::call('instrument:verify', ['version' => $version->key]);
        $output = Artisan::output();
        $this->assertStringContainsString('V4', $output);
        $this->assertStringContainsString('item_count', $output);
    }

    public function test_missing_catch_all_is_caught(): void
    {
        $version = InstrumentVersion::factory()->create();
        $group = AgeGroup::factory()->create(['instrument_version_id' => $version->id, 'code' => 'G1', 'is_alternate' => false]);
        $message = GlobalResultMessage::factory()->create(['instrument_version_id' => $version->id]);
        GlobalScoringRule::factory()->create([
            'instrument_version_id' => $version->id, 'age_group_id' => $group->id,
            'sequence' => 10, 'condition' => ['count' => ['of' => 'red_areas', 'gte' => 1]],
            'result_color_id' => ResultColor::RED, 'global_result_message_id' => $message->id,
        ]);
        // No catch-all {"all":[]} rule.

        Artisan::call('instrument:verify', ['version' => $version->key]);
        $output = Artisan::output();
        $this->assertStringContainsString('V6', $output);
        $this->assertStringContainsString('catch-all', $output);
    }

    public function test_unknown_grammar_operator_is_rejected(): void
    {
        $version = InstrumentVersion::factory()->create();
        $group = AgeGroup::factory()->create(['instrument_version_id' => $version->id, 'code' => 'G1', 'is_alternate' => false]);
        $message = GlobalResultMessage::factory()->create(['instrument_version_id' => $version->id]);
        GlobalScoringRule::factory()->create([
            'instrument_version_id' => $version->id, 'age_group_id' => $group->id,
            'sequence' => 90, 'condition' => ['xor' => []],
            'result_color_id' => ResultColor::GREEN, 'global_result_message_id' => $message->id,
        ]);

        Artisan::call('instrument:verify', ['version' => $version->key]);
        $this->assertStringContainsString('unknown operator', Artisan::output());
    }

    public function test_message_color_mismatch_is_caught(): void
    {
        $version = InstrumentVersion::factory()->create();
        $group = AgeGroup::factory()->create(['instrument_version_id' => $version->id, 'code' => 'G1', 'is_alternate' => false]);
        $greenMessage = GlobalResultMessage::factory()->create([
            'instrument_version_id' => $version->id, 'key' => 'green', 'result_color_id' => ResultColor::GREEN,
        ]);
        GlobalScoringRule::factory()->create([
            'instrument_version_id' => $version->id, 'age_group_id' => $group->id,
            'sequence' => 90, 'condition' => ['all' => []],
            'result_color_id' => ResultColor::RED, // mismatched vs. the message's green
            'global_result_message_id' => $greenMessage->id,
        ]);

        Artisan::call('instrument:verify', ['version' => $version->key]);
        $this->assertStringContainsString('V7', Artisan::output());
    }

    public function test_alternate_coverage_hole_is_caught(): void
    {
        $version = InstrumentVersion::factory()->create();
        $alternate = AgeGroup::factory()->alternate()->create(['instrument_version_id' => $version->id, 'code' => 'G1A']);
        $official = AgeGroup::factory()->create([
            'instrument_version_id' => $version->id, 'code' => 'G2', 'is_alternate' => false,
            'alternate_age_group_id' => $alternate->id,
        ]);

        // Official has MG and SO; alternate has only MG (SO is missing -> hole).
        foreach ([['group' => $official, 'area' => DevelopmentArea::MG], ['group' => $official, 'area' => DevelopmentArea::SO], ['group' => $alternate, 'area' => DevelopmentArea::MG]] as $spec) {
            $item = ScreeningItem::factory()->create([
                'instrument_version_id' => $version->id, 'age_group_id' => $spec['group']->id, 'development_area_id' => $spec['area'],
            ]);
            ScreeningItemOption::factory()->create(['instrument_version_id' => $version->id, 'screening_item_id' => $item->id, 'label' => 'NO', 'sort_order' => 1, 'result_color_id' => ResultColor::RED]);
            ScreeningItemOption::factory()->create(['instrument_version_id' => $version->id, 'screening_item_id' => $item->id, 'label' => 'SI', 'sort_order' => 2, 'result_color_id' => ResultColor::GREEN]);
        }

        Artisan::call('instrument:verify', ['version' => $version->key]);
        $this->assertStringContainsString('V5', Artisan::output());
    }

    public function test_percentile_month_hole_is_caught(): void
    {
        $version = InstrumentVersion::factory()->create();
        HeadCircumferenceReference::factory()->create(['instrument_version_id' => $version->id, 'sex_id' => Sex::MALE, 'age_months' => 1]);
        HeadCircumferenceReference::factory()->create(['instrument_version_id' => $version->id, 'sex_id' => Sex::MALE, 'age_months' => 2]);
        HeadCircumferenceReference::factory()->create(['instrument_version_id' => $version->id, 'sex_id' => Sex::MALE, 'age_months' => 4]);
        // month 3 is missing -- a hole.

        Artisan::call('instrument:verify', ['version' => $version->key]);
        $output = Artisan::output();
        $this->assertStringContainsString('V9', $output);
        $this->assertStringContainsString('hole', $output);
    }

    public function test_raw_sql_tamper_on_a_published_version_is_caught_by_hash_check(): void
    {
        (new InstrumentSeeder)->run();

        DB::table('screening_item')
            ->where('instrument_version_id', InstrumentVersion::where('key', 'edi-2022-2ed')->value('id'))
            ->limit(1)
            ->update(['question' => 'TAMPERED VIA RAW SQL']);

        $exit = Artisan::call('instrument:verify', ['version' => 'edi-2022-2ed']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('V10', $output);
        $this->assertStringContainsString('content_hash', $output);
    }
}
