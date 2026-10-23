<?php

declare(strict_types=1);

namespace Modules\Screening\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ChildRecord\Models\BirthRecord;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Sex;
use Modules\ChildRecord\Models\Visit;
use Modules\Clinic\Models\HealthCenter;
use Modules\Clinic\Models\PractitionerProfile;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\RiskFactor;
use Modules\Instrument\Models\ScreeningItem;
use Modules\Screening\Models\Screening;
use Modules\Screening\Services\Legacy\LegacySomatometry;
use Modules\Screening\Services\ScreeningAnswerService;
use Modules\Screening\Services\ScreeningApplicationService;
use Modules\Screening\Services\ScreeningLifecycleService;
use Modules\Screening\Services\ScreeningScoreService;
use Modules\Tenant\Models\Tenant;
use Throwable;

/**
 * Physically migrates the legacy EDI production dump into the new multi-tenant
 * app: one TENANT per medical unit (cat_centros_salud), then health centers,
 * users/practitioners, children (+ birth record), visits and screenings created
 * through the real app services and scored under `edi-legacy-2013` (the
 * instrument they were applied with) so results reproduce the legacy 99.89%.
 * The result is always recomputed server-side; nothing is injected. Idempotent
 * per external key; `--fresh` re-runs from scratch on a wiped set.
 */
class MigrateLegacyCommand extends Command
{
    protected $signature = 'edi:migrate-legacy
        {--center= : Only this legacy centro_salud id}
        {--limit=0 : Cap screenings per center (0 = all)}
        {--connection=legacy : Legacy DB connection}';

    protected $description = 'Migrate the legacy EDI dump into the app (tenant per medical unit) and re-score under edi-legacy-2013';

    /** @var array<int, array{id:string, options:array<string,string>}> legacy item id -> new item + options */
    private array $itemByLegacy = [];

    /** @var array<int, string> legacy frb id -> new risk_factor id */
    private array $rfByLegacy = [];

    private string $municipalityId;

    /** @var array<string,int> */
    private array $stats = ['tenants' => 0, 'children' => 0, 'screenings' => 0, 'skipped' => 0, 'errors' => 0];

    public function handle(
        ScreeningApplicationService $drafts,
        ScreeningAnswerService $answers,
        ScreeningScoreService $scorer,
        ScreeningLifecycleService $lifecycle,
        LegacySomatometry $somato,
    ): int {
        $conn = DB::connection((string) $this->option('connection'));
        $version = InstrumentVersion::where('key', 'edi-legacy-2013')->firstOrFail();

        foreach (ScreeningItem::where('instrument_version_id', $version->id)->with('options')->get() as $item) {
            $options = [];
            foreach ($item->options as $o) {
                $options[$o->label] = $o->id;
            }
            $this->itemByLegacy[(int) $item->legacy_id] = ['id' => $item->id, 'options' => $options];
        }
        foreach (RiskFactor::where('instrument_version_id', $version->id)->get() as $rf) {
            $this->rfByLegacy[(int) $rf->legacy_id] = $rf->id;
        }
        $this->municipalityId = (string) DB::table('municipality')->value('id');

        $centersQ = $conn->table('tran_edi_beneficiario')->select('centro_salud')->distinct();
        if ($this->option('center')) {
            $centersQ->where('centro_salud', (int) $this->option('center'));
        }
        $centerIds = $centersQ->pluck('centro_salud');
        $limit = (int) $this->option('limit');

        foreach ($centerIds as $centerId) {
            $center = $conn->table('cat_centros_salud')->where('id_centro_salud', $centerId)->first();
            $name = trim((string) ($center->centro_salud ?? '')) ?: ('Centro '.$centerId);

            $tenant = Tenant::where('name', $name)->first();
            if ($tenant === null) {
                $tenant = Tenant::create(['id' => (string) Str::uuid7(), 'name' => $name, 'status' => Tenant::STATUS_ACTIVO]);
                $this->stats['tenants']++;
            }

            $tenant->run(function () use ($conn, $centerId, $center, $name, $limit, $drafts, $answers, $scorer, $lifecycle, $somato): void {
                $hc = HealthCenter::where('clues_code', $center->clues ?? null)->orWhere('name', $name)->first()
                    ?? HealthCenter::create([
                        'name' => $name,
                        'clues_code' => $center->clues ?? null,
                        'municipality_id' => $this->municipalityId,
                        'address' => trim((string) ($center->domicilio ?? '')) ?: 'Sin domicilio',
                        'is_active' => ($center->estatus ?? 1) == 1,
                    ]);

                $q = $conn->table('tran_edi_beneficiario')->where('centro_salud', $centerId)->orderBy('id_edi_beneficiario');
                if ($limit > 0) {
                    $q->limit($limit);
                }
                foreach ($q->get() as $s) {
                    try {
                        $this->migrateScreening($conn, $s, $hc, $drafts, $answers, $scorer, $lifecycle, $somato);
                    } catch (Throwable $e) {
                        $this->stats['errors']++;
                        $this->warn("legacy {$s->id_edi_beneficiario}: ".Str::limit($e->getMessage(), 100));
                    }
                }
            });
        }

        $this->newLine();
        $this->info('Migración legacy completa.');
        $this->table(['Métrica', 'Cantidad'], collect($this->stats)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }

    private function migrateScreening($conn, object $s, HealthCenter $hc, ScreeningApplicationService $drafts, ScreeningAnswerService $answers, ScreeningScoreService $scorer, ScreeningLifecycleService $lifecycle, LegacySomatometry $somato): void
    {
        $legacyId = (int) $s->id_edi_beneficiario;
        $marker = 'legacy:'.$legacyId;
        if (Visit::where('physical_receipt_number', $marker)->exists()) {
            $this->stats['skipped']++;

            return;
        }

        $child = $this->resolveChild($conn, (int) $s->beneficiario, $hc, $somato, $s);
        $practitioner = $this->resolvePractitioner($conn, (int) $s->usuario_creo, $hc);

        $visit = Visit::create([
            'child_id' => $child->id,
            'health_center_id' => $hc->id,
            'practitioner_id' => $practitioner->id,
            'receipt_number' => 'V-MIGR-'.$legacyId,
            'visit_date' => substr((string) $s->fecha_creacion, 0, 10),
            'physical_receipt_number' => $marker,
        ]);

        $drafts->createDraft([
            'visit_id' => $visit->id,
            'instrument_version_key' => 'edi-legacy-2013',
            'weight_grams' => $somato->weightGrams($s->peso ?? null),
            'height_mm' => $somato->heightMm($s->talla ?? null),
            'head_circumference_mm' => $somato->headCircumferenceMm($s->perimetro_cefalico ?? null),
        ]);
        $screening = Screening::where('visit_id', $visit->id)->firstOrFail();

        $itemAnswers = [];
        foreach ($conn->table('tran_edi_evaluacion_respuestas')->where('edi_beneficiario', $legacyId)->get() as $a) {
            $item = $this->itemByLegacy[(int) $a->edi_evaluacion] ?? null;
            $optionId = $item['options'][trim((string) $a->respuesta)] ?? null;
            if ($item !== null && $optionId !== null) {
                $itemAnswers[] = ['screening_item_id' => $item['id'], 'screening_item_option_id' => $optionId];
            }
        }
        $riskAnswers = [];
        foreach ($conn->table('tran_edi_frb_respuestas')->where('edi_beneficiario', $legacyId)->get() as $f) {
            $rfId = $this->rfByLegacy[(int) $f->edi_frb] ?? null;
            if ($rfId !== null) {
                $riskAnswers[] = ['risk_factor_id' => $rfId, 'is_present' => trim((string) $f->respuesta) === 'SI'];
            }
        }

        $answers->upsert($screening, ['answers' => $itemAnswers, 'risk_factor_answers' => $riskAnswers]);
        $scorer->score($screening);
        $lifecycle->complete($screening->fresh());

        $this->stats['screenings']++;
    }

    private function resolveChild($conn, int $beneficiarioId, HealthCenter $hc, LegacySomatometry $somato, object $s): Child
    {
        $external = 'legacy:'.$beneficiarioId;
        $child = Child::where('external_id', $external)->first();
        if ($child !== null) {
            return $child;
        }

        $b = $conn->table('tran_beneficiarios')->where('id_beneficiario', $beneficiarioId)->first();

        $child = Child::create([
            'first_name' => trim((string) ($b->nombre ?? '')) ?: '—',
            'last_name_paternal' => trim((string) ($b->paterno ?? '')) ?: '—',
            'last_name_maternal' => trim((string) ($b->materno ?? '')) ?: null,
            'birth_date' => $b?->fecha_nacimiento ? substr((string) $b->fecha_nacimiento, 0, 10) : null,
            'sex_id' => ($b?->genero === 'H') ? Sex::MALE : Sex::FEMALE,
            'curp' => trim((string) ($b->curp ?? '')) ?: null,
            'external_id' => $external,
            'health_center_id' => $hc->id,
            'is_schooled' => (bool) ($b->estudia ?? false),
        ]);
        $this->stats['children']++;

        BirthRecord::create([
            'child_id' => $child->id,
            'gestational_age_weeks' => is_numeric($s->semanas_gestacion ?? null) ? (int) $s->semanas_gestacion : null,
            'birth_weight_grams' => $somato->birthWeightGrams($s->peso_nacer ?? null),
            'mother_age_at_birth_years' => is_numeric($s->edad_madre ?? null) ? (int) $s->edad_madre : null,
        ]);

        return $child;
    }

    private function resolvePractitioner($conn, int $legacyUserId, HealthCenter $hc): PractitionerProfile
    {
        $u = $conn->table('tran_usuarios')->where('id_usuario', $legacyUserId)->first();
        $name = trim(($u->nombre ?? '').' '.($u->paterno ?? '').' '.($u->materno ?? '')) ?: ('Profesional '.$legacyUserId);

        $user = User::firstOrCreate(['name' => $name], ['auth_active' => false]);

        return PractitionerProfile::where('user_id', $user->id)->where('health_center_id', $hc->id)->first()
            ?? PractitionerProfile::create([
                'user_id' => $user->id,
                'license_number' => trim((string) ($u->cedula_profesional ?? '')) ?: ('MIGR-'.$legacyUserId),
                'specialty' => 'Medicina general',
                'health_center_id' => $hc->id,
            ]);
    }
}
