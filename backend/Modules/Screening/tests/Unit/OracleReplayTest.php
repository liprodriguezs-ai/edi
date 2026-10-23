<?php

declare(strict_types=1);

namespace Modules\Screening\Tests\Unit;

use Modules\Screening\Services\RuleSetLoading\CsvRuleSetLoader;
use Modules\Screening\Services\Scoring\AgeResolver;
use Modules\Screening\Services\Scoring\ScoringEngine;
use Modules\Screening\Services\Scoring\SituationResolver;
use Modules\Screening\Services\Scoring\Values\ItemAnswer;
use Modules\Screening\Services\Scoring\Values\ResultColorKey;
use Modules\Screening\Services\Scoring\Values\RuleSet;
use Modules\Screening\Services\Scoring\Values\ScoringInput;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REQ-SCO-010/011 -- the acceptance gate. Replays all 1,836 production
 * screenings (`edi_oracle.jsonl`) against the `edi-legacy-2013` RuleSet
 * built by `CsvRuleSetLoader`, with NO database. Extends plain
 * `PHPUnit\Framework\TestCase` -- no Laravel boot -- so this is a fast unit
 * test, not a Feature test.
 *
 * Input construction (design D15): production answers/risk factors are fed
 * back UNCHANGED (legacy_id -> item/group, label as-is); perinatal fields
 * are NOT passed (D8's deriver stays inert), so the recorded production
 * answers stand exactly as captured.
 */
final class OracleReplayTest extends TestCase
{
    private const REASON_LEGACY_UNEXPLAINED = 'legacy_unexplained';

    private const CLOSED_REASONS = [self::REASON_LEGACY_UNEXPLAINED];

    /** @var array<string, string> Spanish oracle color -> engine color key */
    private const COLOR_MAP = [
        'VERDE' => ResultColorKey::GREEN,
        'AMARILLO' => ResultColorKey::YELLOW,
        'ROJO' => ResultColorKey::RED,
    ];

    private const OVERALL_QUOTA = 0.998;

    private const PERFECT_GROUPS = ['G1', 'G2', 'G3', 'G4'];

    public function test_replays_the_full_oracle_fixture(): void
    {
        $ruleSet = (new CsvRuleSetLoader)->build('edi-legacy-2013', AgeResolver::MODE_THIRTY_DAY_MONTH);
        $engine = new ScoringEngine;
        $situationResolver = new SituationResolver;

        $rows = $this->readOracleRows();
        $this->assertCount(1836, $rows, 'oracle fixture must have exactly 1,836 rows (design D16)');

        $ledger = $this->readLedger();

        $ledgerLegacyIds = array_flip(array_column($ledger, 'legacy_id'));

        $observations = [];

        foreach ($rows as $row) {
            $legacyId = $row['legacy_id'];
            $input = $this->buildInput($row, $ruleSet);
            $result = $engine->score($input, $ruleSet);

            $expectedColor = self::COLOR_MAP[$row['expected_color']];

            $observations[] = [
                'legacy_id' => $legacyId,
                'group_code' => $input->ageGroupCode,
                'expected' => $expectedColor,
                'computed' => $result->globalColorKey,
            ];

            $this->assertSame(
                (int) $row['expected_ale'],
                $result->alertSignCount,
                "legacy_id {$legacyId}: alert_signs (ALE) mismatch"
            );
            $this->assertSame(
                (int) $row['expected_frb'],
                $result->riskFactorCount,
                "legacy_id {$legacyId}: risk_factors (FRB) mismatch"
            );

            // `sis_code` is entirely derived from the already-computed global
            // color, so a row already registered in the known-discrepancies
            // ledger (global_color mismatch) necessarily produces a
            // downstream situation mismatch too -- it is the SAME known
            // discrepancy, not a new one, so it shares the same tolerance.
            if (in_array($row['expected_situation'], [1, 2, 3], true) && ! isset($ledgerLegacyIds[$legacyId])) {
                $situation = $situationResolver->resolveSituation(null, $result->globalColorKey);
                $this->assertSame(
                    $row['expected_situation'],
                    $situation->sisCode,
                    "legacy_id {$legacyId}: first-time sis_code mismatch"
                );
            }
        }

        $this->assertColorReplay($observations, $ledger, printSummary: true);
    }

    // ── Harness self-tests (design §8) ──────────────────────────────────

    public function test_an_unregistered_mismatch_fails(): void
    {
        $observations = [
            ['legacy_id' => 999901, 'group_code' => 'G7', 'expected' => ResultColorKey::RED, 'computed' => ResultColorKey::YELLOW],
        ];

        $this->expectException(AssertionFailedError::class);
        $this->assertColorReplay($observations, []);
    }

    public function test_a_ledger_entry_whose_row_now_matches_production_fails(): void
    {
        $entry = $this->buildLedgerEntry(999902, 'G10', ResultColorKey::RED, ResultColorKey::YELLOW);
        $observations = [
            // now computes RED, matching production -- the ledger entry is stale.
            ['legacy_id' => 999902, 'group_code' => 'G10', 'expected' => ResultColorKey::RED, 'computed' => ResultColorKey::RED],
        ];

        $this->expectException(AssertionFailedError::class);
        $this->assertColorReplay($observations, [$entry]);
    }

    public function test_a_tampered_ledger_signature_fails(): void
    {
        $entry = $this->buildLedgerEntry(999903, 'G10', ResultColorKey::RED, ResultColorKey::YELLOW);
        $entry['signature'] = 'deadbeefdeadbeef';

        $observations = [
            ['legacy_id' => 999903, 'group_code' => 'G10', 'expected' => ResultColorKey::RED, 'computed' => ResultColorKey::YELLOW],
        ];

        $this->expectException(AssertionFailedError::class);
        $this->assertColorReplay($observations, [$entry]);
    }

    // ── Assertion helper (REQ-SCO-010/011) ──────────────────────────────

    /**
     * @param  array<int, array{legacy_id:int, group_code:string, expected:string, computed:string}>  $observations
     * @param  array<int, array{legacy_id:int, group_code:string, field:string, expected:string, computed:string, reason:string, signature:string}>  $ledger
     */
    private function assertColorReplay(array $observations, array $ledger, bool $printSummary = false): void
    {
        $this->assertLedgerIsValid($ledger);

        $ledgerByLegacyId = [];
        foreach ($ledger as $entry) {
            $ledgerByLegacyId[$entry['legacy_id']] = $entry;
        }

        $unregistered = [];
        $stale = [];
        $reproducedLegacyIds = [];
        $mismatchCount = 0;
        $perGroupMismatch = [];

        foreach ($observations as $observation) {
            $mismatch = $observation['computed'] !== $observation['expected'];
            $entry = $ledgerByLegacyId[$observation['legacy_id']] ?? null;

            if ($entry === null) {
                if ($mismatch) {
                    $unregistered[] = $observation;
                    $mismatchCount++;
                    $perGroupMismatch[$observation['group_code']] = ($perGroupMismatch[$observation['group_code']] ?? 0) + 1;
                }

                continue;
            }

            $reproducesAsRegistered = $mismatch
                && $entry['group_code'] === $observation['group_code']
                && $entry['expected'] === $observation['expected']
                && $entry['computed'] === $observation['computed'];

            if (! $reproducesAsRegistered) {
                $stale[] = $observation['legacy_id'];

                continue;
            }

            $reproducedLegacyIds[$observation['legacy_id']] = true;
            // ledger entries count as mismatches for the quota (design D18).
            $mismatchCount++;
            $perGroupMismatch[$observation['group_code']] = ($perGroupMismatch[$observation['group_code']] ?? 0) + 1;
        }

        $orphanLedgerIds = array_values(array_diff(array_keys($ledgerByLegacyId), array_keys($reproducedLegacyIds)));
        $stale = array_values(array_unique([...$stale, ...$orphanLedgerIds]));

        $this->assertSame(
            [],
            $unregistered,
            'Unregistered color mismatches (legacy_id/group/expected/computed): '.json_encode(array_values($unregistered))
        );
        $this->assertSame(
            [],
            $stale,
            'Ledger entries that no longer reproduce EXACTLY as registered (healed, orphaned, or altered): '.json_encode($stale)
        );

        foreach (self::PERFECT_GROUPS as $groupCode) {
            $this->assertSame(0, $perGroupMismatch[$groupCode] ?? 0, "{$groupCode} must have zero mismatches and zero ledger entries (design D18)");
        }

        $total = count($observations);
        $matchRate = $total > 0 ? 1 - ($mismatchCount / $total) : 1.0;
        $this->assertGreaterThanOrEqual(
            self::OVERALL_QUOTA,
            $matchRate,
            sprintf(
                'Overall match rate %.4f%% (%d/%d) is below the %.1f%% quota',
                $matchRate * 100,
                $total - $mismatchCount,
                $total,
                self::OVERALL_QUOTA * 100
            )
        );

        if ($printSummary) {
            fwrite(STDERR, sprintf(
                "\n[OracleReplayTest] %d/%d matched (%.2f%%), %d ledger entries, mismatches by group: %s\n",
                $total - $mismatchCount,
                $total,
                $matchRate * 100,
                count($ledger),
                json_encode($perGroupMismatch)
            ));
        }
    }

    /**
     * @param  array<int, array{legacy_id:int, group_code:string, field:string, expected:string, computed:string, reason:string, signature:string}>  $ledger
     */
    private function assertLedgerIsValid(array $ledger): void
    {
        foreach ($ledger as $entry) {
            $this->assertContains(
                $entry['reason'],
                self::CLOSED_REASONS,
                "Ledger entry legacy_id {$entry['legacy_id']} has an unknown reason '{$entry['reason']}'."
            );

            $expectedSignature = $this->signatureFor($entry['legacy_id'], $entry['field'], $entry['expected'], $entry['computed'], $entry['reason']);
            $this->assertSame(
                $expectedSignature,
                $entry['signature'],
                "Ledger entry legacy_id {$entry['legacy_id']} has a tampered or incorrect signature."
            );
        }
    }

    private function signatureFor(int $legacyId, string $field, string $expected, string $computed, string $reason): string
    {
        return substr(hash('sha256', "{$legacyId}|{$field}|{$expected}|{$computed}|{$reason}"), 0, 16);
    }

    /**
     * @return array{legacy_id:int, group_code:string, field:string, expected:string, computed:string, reason:string, signature:string, notes:string}
     */
    private function buildLedgerEntry(int $legacyId, string $groupCode, string $expected, string $computed): array
    {
        return [
            'legacy_id' => $legacyId,
            'group_code' => $groupCode,
            'field' => 'global_color',
            'expected' => $expected,
            'computed' => $computed,
            'reason' => self::REASON_LEGACY_UNEXPLAINED,
            'signature' => $this->signatureFor($legacyId, 'global_color', $expected, $computed, self::REASON_LEGACY_UNEXPLAINED),
            'notes' => 'synthetic harness self-test entry',
        ];
    }

    // ── Input construction (design D15) ─────────────────────────────────

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildInput(array $row, RuleSet $ruleSet): ScoringInput
    {
        $groupCode = $ruleSet->groupCodeForLegacyId((int) $row['group_legacy_id'])
            ?? throw new RuntimeException("Unknown group_legacy_id {$row['group_legacy_id']} (oracle legacy_id {$row['legacy_id']}).");

        $answers = [];
        foreach ($row['answers'] as $itemLegacyIdKey => $label) {
            $itemLegacyId = (int) $itemLegacyIdKey;
            $itemRef = $ruleSet->itemForLegacyId($itemLegacyId)
                ?? throw new RuntimeException("Unknown item legacy_id {$itemLegacyId} (oracle legacy_id {$row['legacy_id']}).");

            $item = $ruleSet->item($itemRef['group_code'], $itemRef['item_code']);

            // Seed-integrity tripwire (D15): the resolved option color must
            // equal the row's own answer_colors value.
            $expectedColorKey = self::COLOR_MAP[$row['answer_colors'][$itemLegacyIdKey]];
            $resolvedColorKey = $item->colorForLabel($label);
            if ($resolvedColorKey !== $expectedColorKey) {
                throw new RuntimeException(sprintf(
                    'Seed-integrity tripwire failed: legacy_id %d item %s/%s label "%s" resolves to "%s" but oracle answer_colors says "%s".',
                    $row['legacy_id'],
                    $itemRef['group_code'],
                    $itemRef['item_code'],
                    $label,
                    $resolvedColorKey,
                    $expectedColorKey
                ));
            }

            $answers[] = new ItemAnswer($itemRef['group_code'], $itemRef['item_code'], $label);
        }

        $riskFactorAnswers = [];
        foreach ($row['risk_factors'] as $rfLegacyIdKey => $value) {
            $code = $ruleSet->riskFactorCodeForLegacyId((int) $rfLegacyIdKey)
                ?? throw new RuntimeException("Unknown risk_factor legacy_id {$rfLegacyIdKey} (oracle legacy_id {$row['legacy_id']}).");

            $riskFactorAnswers[$code] = $value === 'SI';
        }

        return ScoringInput::forGroup(
            ageGroupCode: $groupCode,
            answers: $answers,
            riskFactorAnswers: $riskFactorAnswers,
        );
    }

    // ── Fixture reading ──────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readOracleRows(): array
    {
        $path = __DIR__.'/../Fixtures/edi_oracle.jsonl';
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open oracle fixture: {$path}");
        }

        $rows = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $rows[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array{legacy_id:int, group_code:string, field:string, expected:string, computed:string, reason:string, signature:string, notes:string}>
     */
    private function readLedger(): array
    {
        $path = __DIR__.'/../Fixtures/oracle_known_discrepancies.json';

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
