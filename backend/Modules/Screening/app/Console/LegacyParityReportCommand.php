<?php

declare(strict_types=1);

namespace Modules\Screening\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Screening\Services\Legacy\LegacyParityComparator;
use Modules\Screening\Services\RuleSetLoading\CsvRuleSetLoader;
use Modules\Screening\Services\Scoring\AgeResolver;
use Modules\Screening\Services\Scoring\ScoringEngine;
use Modules\Screening\Services\Scoring\SituationResolver;
use Modules\Screening\Services\Scoring\Values\ItemAnswer;
use Modules\Screening\Services\Scoring\Values\ScoringInput;
use Throwable;

/**
 * Replays every legacy EDI screening from the legacy MySQL dump through the NEW
 * scoring engine and writes a CSV comparing, per screening, the legacy stored
 * result against the freshly recomputed one (global color, situation/sis_code,
 * ALE and FRB counters). This is the migration parity document: it proves the
 * new functionality reproduces the old results, straight from the live backup.
 *
 * It does NOT persist anything into the app (no tenants/children): the result is
 * always recomputed server-side (invariant #1) and only compared, never injected.
 */
class LegacyParityReportCommand extends Command
{
    protected $signature = 'edi:legacy-parity
        {--limit=0 : Cap the number of screenings (0 = all)}
        {--connection=legacy : DB connection holding the legacy dump}
        {--out= : CSV output path (default storage/app/legacy/edi-parity.csv)}';

    protected $description = 'Replay legacy EDI screenings through the new engine and report old-vs-new parity to CSV';

    public function handle(LegacyParityComparator $comparator): int
    {
        $ruleSet = (new CsvRuleSetLoader)->build('edi-legacy-2013', AgeResolver::MODE_THIRTY_DAY_MONTH);
        $engine = new ScoringEngine;
        $situations = new SituationResolver;
        $conn = DB::connection((string) $this->option('connection'));
        $limit = (int) $this->option('limit');

        $out = (string) ($this->option('out') ?: storage_path('app/legacy/edi-parity.csv'));
        @mkdir(dirname($out), 0775, true);
        $csv = fopen($out, 'wb');
        fputcsv($csv, [
            'legacy_id', 'group_code',
            'legacy_color', 'new_color', 'color_match',
            'legacy_situation', 'new_situation', 'situation_match',
            'legacy_ale', 'new_ale', 'ale_match',
            'legacy_frb', 'new_frb', 'frb_match',
            'all_match', 'note',
        ]);

        $headerQ = $conn->table('tran_edi_beneficiario')
            ->select('id_edi_beneficiario', 'clasificacion', 'calificacion_global', 'situacion', 'total_ale', 'total_frb')
            ->orderBy('id_edi_beneficiario');
        if ($limit > 0) {
            $headerQ->limit($limit);
        }
        $headers = $headerQ->get();

        $tally = ['total' => 0, 'scored' => 0, 'skipped' => 0, 'color' => 0, 'situation' => 0, 'situation_comparable' => 0, 'ale' => 0, 'frb' => 0, 'all' => 0];
        $bar = $this->output->createProgressBar($headers->count());

        foreach ($headers as $s) {
            $tally['total']++;
            $bar->advance();
            $legacyId = (int) $s->id_edi_beneficiario;

            $groupCode = $ruleSet->groupCodeForLegacyId((int) $s->clasificacion);
            if ($groupCode === null) {
                $tally['skipped']++;
                $this->writeSkip($csv, $legacyId, 'unknown_group:'.$s->clasificacion);

                continue;
            }

            $answers = [];
            $note = null;
            foreach ($conn->table('tran_edi_evaluacion_respuestas')->where('edi_beneficiario', $legacyId)->select('edi_evaluacion', 'respuesta')->get() as $a) {
                $item = $ruleSet->itemForLegacyId((int) $a->edi_evaluacion);
                if ($item === null) {
                    $note = 'unknown_item:'.$a->edi_evaluacion;

                    break;
                }
                $answers[] = new ItemAnswer($item['group_code'], $item['item_code'], trim((string) $a->respuesta));
            }
            if ($note !== null) {
                $tally['skipped']++;
                $this->writeSkip($csv, $legacyId, $note);

                continue;
            }

            $riskFactors = [];
            foreach ($conn->table('tran_edi_frb_respuestas')->where('edi_beneficiario', $legacyId)->select('edi_frb', 'respuesta')->get() as $f) {
                $code = $ruleSet->riskFactorCodeForLegacyId((int) $f->edi_frb);
                if ($code !== null) {
                    $riskFactors[$code] = trim((string) $f->respuesta) === 'SI';
                }
            }

            try {
                $result = $engine->score(
                    ScoringInput::forGroup(ageGroupCode: $groupCode, answers: $answers, riskFactorAnswers: $riskFactors),
                    $ruleSet,
                );
            } catch (Throwable $e) {
                $tally['skipped']++;
                $this->writeSkip($csv, $legacyId, 'engine_error:'.substr($e->getMessage(), 0, 60));

                continue;
            }

            // First-time situation only. Recovered/follow-up (legacy 4/5/6)
            // depend on prior-screening history that is out of scope for
            // stateless parity (mirrors OracleReplayTest, which asserts 1/2/3
            // and skips the rest); the comparator marks those n/a.
            $sisCode = $situations->resolveSituation(null, $result->globalColorKey)->sisCode;

            $row = $comparator->compare(
                legacyId: $legacyId,
                groupCode: $groupCode,
                legacy: [
                    'global_color' => (string) $s->calificacion_global,
                    'situacion' => $s->situacion,
                    'total_ale' => $s->total_ale,
                    'total_frb' => $s->total_frb,
                ],
                computed: [
                    'global_color' => $result->globalColorKey,
                    'sis_code' => $sisCode,
                    'alert_sign_count' => $result->alertSignCount,
                    'risk_factor_count' => $result->riskFactorCount,
                ],
            );

            $tally['scored']++;
            $tally['color'] += $row['color_match'] ? 1 : 0;
            if ($row['situation_match'] !== null) {
                $tally['situation_comparable']++;
                $tally['situation'] += $row['situation_match'] ? 1 : 0;
            }
            $tally['ale'] += $row['ale_match'] ? 1 : 0;
            $tally['frb'] += $row['frb_match'] ? 1 : 0;
            $tally['all'] += $row['all_match'] ? 1 : 0;

            fputcsv($csv, [
                $row['legacy_id'], $row['group_code'],
                $row['legacy_color'], $row['new_color'], $this->yn($row['color_match']),
                $row['legacy_situation'], $row['new_situation'], $this->yn3($row['situation_match']),
                $row['legacy_ale'], $row['new_ale'], $this->yn($row['ale_match']),
                $row['legacy_frb'], $row['new_frb'], $this->yn($row['frb_match']),
                $this->yn($row['all_match']), $row['situation_match'] === null ? 'situacion_requiere_historial' : '',
            ]);
        }

        $bar->finish();
        fclose($csv);
        $this->newLine(2);

        $scored = max(1, $tally['scored']);
        $this->info("Documento de paridad: {$out}");
        $this->table(
            ['Métrica', 'Coinciden', 'De', '%'],
            [
                ['Color global', $tally['color'], $tally['scored'], $this->pct($tally['color'], $scored)],
                ['Situación (1/2/3, primera vez)', $tally['situation'], $tally['situation_comparable'], $this->pct($tally['situation'], max(1, $tally['situation_comparable']))],
                ['ALE (señales alerta)', $tally['ale'], $tally['scored'], $this->pct($tally['ale'], $scored)],
                ['FRB (factores riesgo)', $tally['frb'], $tally['scored'], $this->pct($tally['frb'], $scored)],
                ['TODOS los campos', $tally['all'], $tally['scored'], $this->pct($tally['all'], $scored)],
            ]
        );
        $this->line("Total: {$tally['total']}  ·  calificados: {$tally['scored']}  ·  omitidos: {$tally['skipped']}");

        return self::SUCCESS;
    }

    private function writeSkip($csv, int $legacyId, string $note): void
    {
        fputcsv($csv, [$legacyId, '', '', '', '', '', '', '', '', '', '', '', '', '', 'SKIP', $note]);
    }

    private function yn(bool $v): string
    {
        return $v ? 'SI' : 'NO';
    }

    private function yn3(?bool $v): string
    {
        return $v === null ? 'NA' : ($v ? 'SI' : 'NO');
    }

    private function pct(int $n, int $d): string
    {
        return number_format($d > 0 ? 100 * $n / $d : 0, 2).'%';
    }
}
