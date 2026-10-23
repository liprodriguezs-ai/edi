<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit\RuleSetLoading;

use Modules\Screening\Services\RuleSetLoading\CsvRuleSetLoader;
use Modules\Screening\Services\Scoring\AgeResolver;
use PHPUnit\Framework\TestCase;

/**
 * REQ-SCO-002: `CsvRuleSetLoader` builds a complete RuleSet with NO
 * database connection. Extends plain PHPUnit\Framework\TestCase --
 * no Laravel boot -- to prove the "no database" claim structurally, not
 * just by assertion.
 */
final class CsvRuleSetLoaderTest extends TestCase
{
    private const EXPECTED_DESIGNATED_GROUPS = [
        'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G9', 'G10', 'G11', 'G12', 'G13', 'G14',
    ];

    public function test_builds_edi_legacy_2013_with_exact_counts(): void
    {
        $ruleSet = (new CsvRuleSetLoader)->build('edi-legacy-2013', AgeResolver::MODE_THIRTY_DAY_MONTH);

        $this->assertCount(15, $ruleSet->officialGroups());
        $this->assertCount(29, $ruleSet->ageGroups);
        $this->assertCount(451, $ruleSet->items);
        $this->assertCount(92, $ruleSet->areaRules);
        $this->assertCount(59, $ruleSet->alternateAreaRules);
        $this->assertCount(7, $ruleSet->riskFactors);
        $this->assertCount(118, $ruleSet->headCircumferenceBands);

        $globalRuleCount = array_sum(array_map('count', $ruleSet->globalRules));
        $this->assertSame(59, $globalRuleCount);
    }

    public function test_head_circumference_designation_covers_g1_through_g14_only(): void
    {
        $ruleSet = (new CsvRuleSetLoader)->build('edi-legacy-2013', AgeResolver::MODE_THIRTY_DAY_MONTH);

        $actualGroups = array_keys($ruleSet->headCircumferenceItemByGroup);
        sort($actualGroups);
        $expectedGroups = self::EXPECTED_DESIGNATED_GROUPS;
        sort($expectedGroups);

        $this->assertSame($expectedGroups, $actualGroups);
        $this->assertArrayNotHasKey('G15', $ruleSet->headCircumferenceItemByGroup);

        foreach (self::EXPECTED_DESIGNATED_GROUPS as $groupCode) {
            $itemCode = $ruleSet->headCircumferenceItemByGroup[$groupCode];
            $item = $ruleSet->item($groupCode, $itemCode);

            $this->assertSame('EN', $item->areaCode, "designated item of {$groupCode} must be EN");
            $this->assertMatchesRegularExpression(
                '/perímetro cefálico/i',
                $this->questionFor($groupCode, $itemCode),
                "designated item of {$groupCode} must ask about perímetro cefálico (D10 tripwire)"
            );
        }
    }

    /**
     * The RuleSet itself does not carry `question` text (it is UI copy, out
     * of scope for the pure engine) -- re-read the CSV row directly for
     * this test-only tripwire (design D10).
     */
    private function questionFor(string $groupCode, string $itemCode): string
    {
        $csvDir = dirname(__DIR__, 4).'/Instrument/database/seeders/csv/edi-legacy-2013';
        $handle = fopen("{$csvDir}/screening_item.csv", 'rb');
        $header = fgetcsv($handle, escape: '\\');

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $record = array_combine($header, $row);
            if ($record['group_code'] === $groupCode && $record['code'] === $itemCode) {
                fclose($handle);

                return $record['question'];
            }
        }

        fclose($handle);
        $this->fail("Item {$groupCode}/{$itemCode} not found in screening_item.csv");
    }

    public function test_parity_with_edi_2022_2ed_grammar_shape(): void
    {
        $legacy = (new CsvRuleSetLoader)->build('edi-legacy-2013', AgeResolver::MODE_THIRTY_DAY_MONTH);
        $modern = (new CsvRuleSetLoader)->build('edi-2022-2ed', AgeResolver::MODE_CALENDAR);

        $this->assertSame(count($legacy->items), count($modern->items));
        $this->assertSame(array_keys($legacy->officialGroups()), array_keys($modern->officialGroups()));
    }
}
