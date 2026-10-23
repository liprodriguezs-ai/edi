<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit\Legacy;

use Modules\Screening\Services\Legacy\LegacyParityComparator;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use PHPUnit\Framework\TestCase;

/**
 * Pure comparison between the legacy stored EDI result and the result the new
 * engine recomputes. No database, no Laravel boot. This is the heart of the
 * migration parity report: it decides, per field, whether old == new.
 */
final class LegacyParityComparatorTest extends TestCase
{
    private LegacyParityComparator $comparator;

    protected function setUp(): void
    {
        $this->comparator = new LegacyParityComparator;
    }

    public function test_all_fields_matching_produces_a_full_match_row(): void
    {
        $row = $this->comparator->compare(
            legacyId: 1001,
            groupCode: 'G13',
            legacy: ['global_color' => 'VERDE', 'situacion' => 1, 'total_ale' => 0, 'total_frb' => 2],
            computed: ['global_color' => ResultColorKey::GREEN, 'sis_code' => 1, 'alert_sign_count' => 0, 'risk_factor_count' => 2],
        );

        $this->assertSame(1001, $row['legacy_id']);
        $this->assertSame('G13', $row['group_code']);
        $this->assertTrue($row['color_match']);
        $this->assertTrue($row['situation_match']);
        $this->assertTrue($row['ale_match']);
        $this->assertTrue($row['frb_match']);
        $this->assertTrue($row['all_match']);
        // legacy side is normalized to the engine's color vocabulary for the report
        $this->assertSame(ResultColorKey::GREEN, $row['legacy_color']);
        $this->assertSame(ResultColorKey::GREEN, $row['new_color']);
    }

    public function test_a_color_mismatch_breaks_the_overall_match(): void
    {
        $row = $this->comparator->compare(
            legacyId: 1002,
            groupCode: 'G10',
            legacy: ['global_color' => 'ROJO', 'situacion' => 3, 'total_ale' => 1, 'total_frb' => 0],
            computed: ['global_color' => ResultColorKey::YELLOW, 'sis_code' => 2, 'alert_sign_count' => 1, 'risk_factor_count' => 0],
        );

        $this->assertFalse($row['color_match']);
        $this->assertFalse($row['situation_match']); // situation derives from color
        $this->assertTrue($row['ale_match']);
        $this->assertTrue($row['frb_match']);
        $this->assertFalse($row['all_match']);
        $this->assertSame(ResultColorKey::RED, $row['legacy_color']);
        $this->assertSame(ResultColorKey::YELLOW, $row['new_color']);
    }

    public function test_counter_mismatch_alone_breaks_the_overall_match(): void
    {
        $row = $this->comparator->compare(
            legacyId: 1003,
            groupCode: 'G7',
            legacy: ['global_color' => 'AMARILLO', 'situacion' => 2, 'total_ale' => 3, 'total_frb' => 1],
            computed: ['global_color' => ResultColorKey::YELLOW, 'sis_code' => 2, 'alert_sign_count' => 2, 'risk_factor_count' => 1],
        );

        $this->assertTrue($row['color_match']);
        $this->assertFalse($row['ale_match']);
        $this->assertFalse($row['all_match']);
        $this->assertSame(3, $row['legacy_ale']);
        $this->assertSame(2, $row['new_ale']);
    }

    public function test_recovered_or_followup_situation_is_marked_not_applicable(): void
    {
        // Legacy situation 6 (EN SEGUIMIENTO) needs prior-screening history, out
        // of scope for stateless parity. It must NOT count as a mismatch: when
        // color/ALE/FRB all match, the row is a full match.
        $row = $this->comparator->compare(
            legacyId: 2001,
            groupCode: 'G12',
            legacy: ['global_color' => 'ROJO', 'situacion' => 6, 'total_ale' => 2, 'total_frb' => 1],
            computed: ['global_color' => ResultColorKey::RED, 'sis_code' => 3, 'alert_sign_count' => 2, 'risk_factor_count' => 1],
        );

        $this->assertNull($row['situation_match']);
        $this->assertSame(6, $row['legacy_situation']);
        $this->assertTrue($row['color_match']);
        $this->assertTrue($row['all_match']);
    }

    public function test_unknown_legacy_color_is_reported_not_crashed(): void
    {
        $row = $this->comparator->compare(
            legacyId: 1004,
            groupCode: 'G1',
            legacy: ['global_color' => '', 'situacion' => 1, 'total_ale' => 0, 'total_frb' => 0],
            computed: ['global_color' => ResultColorKey::GREEN, 'sis_code' => 1, 'alert_sign_count' => 0, 'risk_factor_count' => 0],
        );

        $this->assertSame('UNKNOWN', $row['legacy_color']);
        $this->assertFalse($row['color_match']);
        $this->assertFalse($row['all_match']);
    }
}
