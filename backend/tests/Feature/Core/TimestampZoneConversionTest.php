<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Support\TimestampZoneConverter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Storage moved from Mexico wall clock to UTC. The conversion must follow
 * Postgres tz rules, so pre-2022 Mexican DST rows (UTC-5 in summer) shift by
 * 5 hours while winter and post-2022 rows shift by 6.
 */
final class TimestampZoneConversionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_20_130000_convert_legacy_timestamps_to_utc.php';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE TABLE "tz_conversion_probe" (
            "id" integer PRIMARY KEY,
            "happened_at" timestamp(0) without time zone NULL,
            "precise_at" timestamp(6) without time zone NULL,
            "civil_day" date NULL
        )');
        DB::table('tz_conversion_probe')->insert([
            ['id' => 1, 'happened_at' => '2020-07-01 12:00:00', 'precise_at' => '2020-07-01 12:00:00.123456', 'civil_day' => '2020-07-01'],
            ['id' => 2, 'happened_at' => '2020-01-15 12:00:00', 'precise_at' => null, 'civil_day' => '2020-01-15'],
            ['id' => 3, 'happened_at' => '2024-07-01 12:00:00', 'precise_at' => null, 'civil_day' => '2024-07-01'],
            ['id' => 4, 'happened_at' => null, 'precise_at' => null, 'civil_day' => null],
        ]);
    }

    /** @return array<int, array{happened_at: ?string, precise_at: ?string, civil_day: ?string}> */
    private function probeRows(): array
    {
        return DB::table('tz_conversion_probe')->orderBy('id')->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => [
                'happened_at' => $row->happened_at,
                'precise_at' => $row->precise_at,
                'civil_day' => $row->civil_day,
            ]])->all();
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }

    public function test_converter_shifts_mexico_wall_clock_to_utc_with_historical_dst(): void
    {
        app(TimestampZoneConverter::class)->convert('America/Mexico_City', 'UTC', ['tz_conversion_probe']);

        $rows = $this->probeRows();
        self::assertSame('2020-07-01 17:00:00', $rows[1]['happened_at']);
        self::assertSame('2020-07-01 17:00:00.123456', $rows[1]['precise_at']);
        self::assertSame('2020-01-15 18:00:00', $rows[2]['happened_at']);
        self::assertSame('2024-07-01 18:00:00', $rows[3]['happened_at']);
        self::assertNull($rows[4]['happened_at']);
        self::assertNull($rows[2]['precise_at']);
        self::assertSame(['2020-07-01', '2020-01-15', '2024-07-01', null], array_column($rows, 'civil_day'));
    }

    public function test_reverse_conversion_restores_the_original_wall_clock(): void
    {
        $before = $this->probeRows();
        $converter = app(TimestampZoneConverter::class);

        $converter->convert('America/Mexico_City', 'UTC', ['tz_conversion_probe']);
        $converter->convert('UTC', 'America/Mexico_City', ['tz_conversion_probe']);

        self::assertSame($before, $this->probeRows());
    }

    public function test_converter_discovers_every_timestamp_column_but_never_date_columns(): void
    {
        $columns = app(TimestampZoneConverter::class)->timestampColumns();

        self::assertSame(['happened_at', 'precise_at'], $columns['tz_conversion_probe']);
        self::assertContains('created_at', $columns['child']);
        foreach ($columns as $table => $tableColumns) {
            self::assertNotContains('birth_date', $tableColumns, $table);
        }
    }

    public function test_converter_rejects_unknown_zones(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(TimestampZoneConverter::class)->convert('Mexico/Nowhere', 'UTC', ['tz_conversion_probe']);
    }

    public function test_migration_converts_from_the_configured_legacy_zone_and_down_restores(): void
    {
        $before = $this->probeRows();
        config(['app.legacy_storage_timezone' => 'America/Mexico_City']);
        $migration = $this->migration();

        $migration->up();
        self::assertSame('2020-07-01 17:00:00', $this->probeRows()[1]['happened_at']);

        $migration->down();
        self::assertSame($before, $this->probeRows());
    }

    public function test_migration_is_a_no_op_when_the_legacy_zone_is_unset_or_utc(): void
    {
        $before = $this->probeRows();

        foreach ([null, '', 'UTC'] as $zone) {
            config(['app.legacy_storage_timezone' => $zone]);
            $migration = $this->migration();
            $migration->up();
            $migration->down();
            self::assertSame($before, $this->probeRows(), var_export($zone, true));
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tz_conversion_probe');

        parent::tearDown();
    }
}
