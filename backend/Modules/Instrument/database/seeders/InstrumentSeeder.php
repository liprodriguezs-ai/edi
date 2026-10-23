<?php

namespace Modules\Instrument\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Instrument\Models\Instrument;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Services\InstrumentContentHasher;
use Modules\Instrument\Services\InstrumentCsvLoader;
use Modules\Instrument\Services\InstrumentVerificationService;
use RuntimeException;

/**
 * Seeds the two EDI editions from their committed per-version CSV
 * directories (design §6/D20/D21).
 *
 * Publish flow per version: exists + published? recompute hash -> match:
 * skip (no-op, REQ-INS-012); mismatch: ABORT loudly, DB unchanged. Else:
 * create draft -> load CSVs -> run every verify check in-process -> abort if
 * any fails -> compute content_hash -> set published_at (+ is_default for
 * the platform default).
 */
class InstrumentSeeder extends Seeder
{
    /**
     * @var array<int, array{key: string, name: string, age_calculation_mode: string, is_default: bool, source_note: string}>
     */
    private const VERSIONS = [
        [
            'key' => 'edi-legacy-2013',
            'name' => 'EDI legado 2013 (bug-compatible)',
            'age_calculation_mode' => InstrumentVersion::AGE_MODE_THIRTY_DAY_MONTH,
            'is_default' => false,
            'source_note' => 'Transcripción fiel del sistema legado (extendevs_prod_edi.sql), incluyendo su alcance '
                .'limitado de señales de alerta/factores de riesgo (grupos 1-4 únicamente, D15) y las reglas del '
                .'grupo 15 y de grupos alternos confirmadas empíricamente contra el oráculo de 1,836 pruebas '
                .'reales (design.md §13): reproduce 1,834/1,836 = 99.9%.',
        ],
        [
            'key' => 'edi-2022-2ed',
            'name' => 'EDI 2ª edición (2022)',
            'age_calculation_mode' => InstrumentVersion::AGE_MODE_CALENDAR,
            'is_default' => true,
            'source_note' => 'Mismo banco de reactivos y las mismas reglas que edi-legacy-2013 (D14/D15/§13); '
                .'difiere únicamente en age_calculation_mode (calendario real, no mes de 30 días). Versión '
                .'predeterminada para pruebas nuevas.',
        ],
    ];

    public function run(): void
    {
        $instrument = Instrument::query()->firstOrCreate(
            ['id' => Instrument::EDI],
            ['id' => Instrument::EDI, 'key' => 'edi', 'name' => 'Evaluación del Desarrollo Infantil (EDI)', 'description' => null]
        );

        $hasher = new InstrumentContentHasher;
        $verifier = new InstrumentVerificationService($hasher);

        foreach (self::VERSIONS as $spec) {
            $this->seedVersion($instrument, $spec, $hasher, $verifier);
        }
    }

    /**
     * @param  array{key: string, name: string, age_calculation_mode: string, is_default: bool, source_note: string}  $spec
     */
    private function seedVersion(
        Instrument $instrument,
        array $spec,
        InstrumentContentHasher $hasher,
        InstrumentVerificationService $verifier
    ): void {
        $existing = InstrumentVersion::query()
            ->where('instrument_id', $instrument->id)
            ->where('key', $spec['key'])
            ->first();

        if ($existing !== null && $existing->isPublished()) {
            $recomputed = $hasher->hash($existing);

            if ($recomputed === $existing->content_hash) {
                // Idempotent no-op (REQ-INS-012): already published, content unchanged.
                return;
            }

            throw new RuntimeException(
                "InstrumentSeeder: published version '{$spec['key']}' content_hash mismatch "
                ."(stored={$existing->content_hash}, recomputed={$recomputed}). "
                .'Published content must never be re-seeded over -- the CSVs changed after publish. Aborting.'
            );
        }

        DB::transaction(function () use ($instrument, $spec, $hasher, $verifier, $existing) {
            $version = $existing ?? InstrumentVersion::query()->create([
                'id' => $spec['key'] === 'edi-legacy-2013' ? InstrumentVersion::EDI_LEGACY_2013 : InstrumentVersion::EDI_2022_2ED,
                'instrument_id' => $instrument->id,
                'key' => $spec['key'],
                'name' => $spec['name'],
                'age_calculation_mode' => $spec['age_calculation_mode'],
                'is_default' => false,
                'published_at' => null,
                'content_hash' => null,
                'source_note' => $spec['source_note'],
            ]);

            $csvDir = __DIR__."/csv/{$spec['key']}";
            (new InstrumentCsvLoader)->load($version, $csvDir);

            $failures = $verifier->verify($version);
            if ($failures !== []) {
                throw new RuntimeException(
                    "InstrumentSeeder: version '{$spec['key']}' failed verification before publish:\n- "
                    .implode("\n- ", $failures)
                );
            }

            $version->content_hash = $hasher->hash($version);
            $version->published_at = now();
            $version->is_default = $spec['is_default'];
            $version->save();
        });
    }
}
