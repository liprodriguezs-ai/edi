<?php

declare(strict_types=1);

namespace Modules\Instrument\Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Instrument\Database\Seeders\InstrumentSeeder;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\AreaScoringRule;
use Modules\Instrument\Models\GlobalScoringRule;
use Modules\Instrument\Models\HeadCircumferenceReference;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\RiskFactor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Instrument\Models\ScreeningItemOption;
use RuntimeException;
use Tests\TestCase;

/**
 * REQ-INS-004/005/006/007/012 — the seeder itself (task 3.7). Central catalogs
 * (Sex, result_color, development_area, application_position) must be seeded
 * first: InstrumentCsvLoader resolves their keys.
 */
final class InstrumentSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogSeeder)->run();
    }

    private function seedInstrument(): void
    {
        (new InstrumentSeeder)->run();
    }

    public function test_exact_row_counts_per_version(): void
    {
        $this->seedInstrument();

        foreach (['edi-legacy-2013', 'edi-2022-2ed'] as $key) {
            $version = InstrumentVersion::where('key', $key)->firstOrFail();

            $this->assertSame(29, AgeGroup::where('instrument_version_id', $version->id)->count(), "{$key} age_group");
            $this->assertSame(451, ScreeningItem::where('instrument_version_id', $version->id)->count(), "{$key} screening_item");
            $this->assertSame(902, ScreeningItemOption::where('instrument_version_id', $version->id)->count(), "{$key} screening_item_option");
            $this->assertSame(7, RiskFactor::where('instrument_version_id', $version->id)->count(), "{$key} risk_factor");
            $this->assertSame(118, HeadCircumferenceReference::where('instrument_version_id', $version->id)->count(), "{$key} head_circumference_reference");
            $this->assertSame(151, AreaScoringRule::where('instrument_version_id', $version->id)->count(), "{$key} area_scoring_rule");
            $this->assertSame(59, GlobalScoringRule::where('instrument_version_id', $version->id)->count(), "{$key} global_scoring_rule");
            $this->assertTrue($version->isPublished());
        }
    }

    public function test_only_edi_2022_2ed_is_default(): void
    {
        $this->seedInstrument();

        $this->assertFalse(InstrumentVersion::where('key', 'edi-legacy-2013')->firstOrFail()->is_default);
        $this->assertTrue(InstrumentVersion::where('key', 'edi-2022-2ed')->firstOrFail()->is_default);
    }

    public function test_ids_are_deterministic_across_fresh_migrate_and_reseed(): void
    {
        $this->seedInstrument();

        $before = ScreeningItem::query()
            ->join('age_group', 'age_group.id', '=', 'screening_item.age_group_id')
            ->where('age_group.code', 'G1')
            ->where('screening_item.instrument_version_id', InstrumentVersion::where('key', 'edi-legacy-2013')->value('id'))
            ->where('screening_item.code', 'MG1.2')
            ->value('screening_item.id');

        $this->assertNotNull($before);

        Artisan::call('migrate:fresh');
        (new CatalogSeeder)->run();
        $this->seedInstrument();

        $after = ScreeningItem::query()
            ->join('age_group', 'age_group.id', '=', 'screening_item.age_group_id')
            ->where('age_group.code', 'G1')
            ->where('screening_item.instrument_version_id', InstrumentVersion::where('key', 'edi-legacy-2013')->value('id'))
            ->where('screening_item.code', 'MG1.2')
            ->value('screening_item.id');

        $this->assertSame($before, $after, 'UUIDv5 must be byte-identical across migrate:fresh + re-seed (D6).');
    }

    public function test_reseed_is_a_no_op(): void
    {
        $this->seedInstrument();

        $countsBefore = [
            AgeGroup::count(),
            ScreeningItem::count(),
            ScreeningItemOption::count(),
        ];
        $hashesBefore = InstrumentVersion::pluck('content_hash', 'key')->all();

        $this->seedInstrument();

        $countsAfter = [
            AgeGroup::count(),
            ScreeningItem::count(),
            ScreeningItemOption::count(),
        ];
        $hashesAfter = InstrumentVersion::pluck('content_hash', 'key')->all();

        $this->assertSame($countsBefore, $countsAfter);
        $this->assertSame($hashesBefore, $hashesAfter);
    }

    public function test_tampered_published_csv_aborts_without_changing_the_database(): void
    {
        $this->seedInstrument();

        $countBefore = ScreeningItem::count();
        $hashBefore = InstrumentVersion::where('key', 'edi-legacy-2013')->value('content_hash');

        // Simulate a divergent CSV: mutate one published row via raw SQL
        // (bypassing the observer, exactly like REQ-INS-003's tampering
        // scenario), then re-run the seeder -- it recomputes the hash for the
        // already-published version and must ABORT loudly rather than
        // silently accept or overwrite.
        DB::table('screening_item')
            ->where('instrument_version_id', InstrumentVersion::where('key', 'edi-legacy-2013')->value('id'))
            ->limit(1)
            ->update(['question' => 'TAMPERED']);

        try {
            $this->seedInstrument();
            $this->fail('Expected InstrumentSeeder to throw on a published content_hash mismatch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('content_hash mismatch', $e->getMessage());
        }

        // Row count is unaffected by the aborted seeder run, and the STORED
        // content_hash is untouched (still the ORIGINAL, pre-tamper value) --
        // the seeder never writes over a published version on mismatch, it
        // only recomputes-and-compares, then aborts. The raw-SQL tamper
        // itself (committed outside the seeder, on purpose, to simulate
        // REQ-INS-003's scenario) is the only change left in the database.
        $this->assertSame($countBefore, ScreeningItem::count());
        $this->assertSame($hashBefore, InstrumentVersion::where('key', 'edi-legacy-2013')->value('content_hash'));
    }

    public function test_g12a_co_r_items_are_normalized_to_area_co(): void
    {
        $this->seedInstrument();

        $version = InstrumentVersion::where('key', 'edi-legacy-2013')->firstOrFail();
        $group = AgeGroup::where('instrument_version_id', $version->id)->where('code', 'G12A')->firstOrFail();

        $items = ScreeningItem::where('age_group_id', $group->id)
            ->with('development_area', 'options.result_color')
            ->get()
            ->filter(fn (ScreeningItem $i) => str_starts_with($i->code, 'CO-R'));

        $this->assertCount(3, $items);
        foreach ($items as $item) {
            $this->assertSame('CO', $item->development_area->code);
            $this->assertStringStartsWith('CO-R ', $item->code);
            $colors = $item->options->pluck('result_color.key')->sort()->values()->all();
            $this->assertSame(['red', 'yellow'], $colors, 'CO-R items must be RED/YELLOW only, no green option.');
        }
    }

    public function test_g1a_reuses_g1_code_with_alternate_colors(): void
    {
        $this->seedInstrument();

        $version = InstrumentVersion::where('key', 'edi-legacy-2013')->firstOrFail();
        $g1 = AgeGroup::where('instrument_version_id', $version->id)->where('code', 'G1')->firstOrFail();
        $g1a = AgeGroup::where('instrument_version_id', $version->id)->where('code', 'G1A')->firstOrFail();

        $official = ScreeningItem::where('age_group_id', $g1->id)->where('code', 'MG1.2')
            ->with('options.result_color')->firstOrFail();
        $alternate = ScreeningItem::where('age_group_id', $g1a->id)->where('code', 'MG1.2')
            ->with('options.result_color', 'application_position')->firstOrFail();

        $officialColors = $official->options->sortBy('sort_order')->pluck('result_color.key')->values()->all();
        $alternateColors = $alternate->options->sortBy('sort_order')->pluck('result_color.key')->values()->all();

        $this->assertSame(['red', 'green'], $officialColors);
        $this->assertSame(['red', 'yellow'], $alternateColors);
        $this->assertSame('not_applicable', $alternate->application_position->key);
    }

    public function test_ale15_options_preserve_green_first_order(): void
    {
        $this->seedInstrument();

        $version = InstrumentVersion::where('key', 'edi-legacy-2013')->firstOrFail();
        $g15 = AgeGroup::where('instrument_version_id', $version->id)->where('code', 'G15')->firstOrFail();

        $item = ScreeningItem::where('age_group_id', $g15->id)->where('code', 'ALE15.1')
            ->with('options.result_color')->firstOrFail();

        $ordered = $item->options->sortBy('sort_order')->values();
        $this->assertSame('NO', $ordered[0]->label);
        $this->assertSame('green', $ordered[0]->result_color->key);
        $this->assertSame('SI', $ordered[1]->label);
        $this->assertSame('yellow', $ordered[1]->result_color->key);
    }
}
