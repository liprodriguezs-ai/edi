<?php

namespace Modules\Screening\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\ChildRecord\Models\Child;
use Modules\ChildRecord\Models\Visit;
use Modules\Instrument\Models\AgeGroup;
use Modules\Instrument\Models\GlobalResultMessage;
use Modules\Instrument\Models\InstrumentVersion;
use Modules\Instrument\Models\ResultColor;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Header of an applied EDI screening (design §3). The result NEVER comes
 * from the client (REQ-SCR-003): $fillable carries ONLY the somatometry +
 * notes fields a FormRequest can whitelist for update. Every other field
 * (frozen version pointer, resolved groups/ages, and the full computed
 * result) is written exclusively via forceFill/setRawAttributes from
 * Services\ScreeningApplicationService / ScreeningScoreService /
 * ScreeningLifecycleService.
 */
#[ObservedBy([GlobalObserver::class])]
class Screening extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'screening';

    /** The applied group was resolved from the child's age (default). */
    public const SELECTION_MODE_AGE = 'age';

    /** The evaluator picked the applied group ("Prueba EDI a elección"). */
    public const SELECTION_MODE_CHOSEN = 'chosen';

    public const SELECTION_MODES = [self::SELECTION_MODE_AGE, self::SELECTION_MODE_CHOSEN];

    protected $fillable = [
        'weight_grams',
        'height_mm',
        'head_circumference_mm',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'chronological_age_days' => 'integer',
            'corrected_age_days' => 'integer',
            'weight_grams' => 'integer',
            'height_mm' => 'integer',
            'head_circumference_mm' => 'integer',
            'alert_sign_count' => 'integer',
            'risk_factor_count' => 'integer',
            'scored_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isAgeGroupChosen(): bool
    {
        return $this->age_group_selection_mode === self::SELECTION_MODE_CHOSEN;
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class, 'visit_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function instrument_version(): BelongsTo
    {
        return $this->belongsTo(InstrumentVersion::class, 'instrument_version_id');
    }

    public function age_group(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class, 'age_group_id');
    }

    public function applied_age_group(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class, 'applied_age_group_id');
    }

    /**
     * The group the age rules would apply (corrected age included). Differs
     * from applied_age_group only in "Prueba EDI a elección".
     */
    public function age_derived_age_group(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class, 'age_derived_age_group_id');
    }

    public function screening_status(): BelongsTo
    {
        return $this->belongsTo(ScreeningStatus::class, 'screening_status_id');
    }

    public function result_color(): BelongsTo
    {
        return $this->belongsTo(ResultColor::class, 'result_color_id');
    }

    public function global_result_message(): BelongsTo
    {
        return $this->belongsTo(GlobalResultMessage::class, 'global_result_message_id');
    }

    public function situation(): BelongsTo
    {
        return $this->belongsTo(ScreeningSituation::class, 'situation_id');
    }

    public function previous_screening(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_screening_id');
    }

    public function previous_result_color(): BelongsTo
    {
        return $this->belongsTo(ResultColor::class, 'previous_result_color_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ScreeningAnswer::class, 'screening_id');
    }

    public function area_results(): HasMany
    {
        return $this->hasMany(ScreeningAreaResult::class, 'screening_id');
    }

    public function risk_factor_answers(): HasMany
    {
        return $this->hasMany(ScreeningRiskFactorAnswer::class, 'screening_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ScreeningFinding::class, 'screening_id');
    }

    public function score_runs(): HasMany
    {
        return $this->hasMany(ScreeningScoreRun::class, 'screening_id');
    }

    public function confirmatory_test(): HasOne
    {
        return $this->hasOne(ConfirmatoryTest::class, 'screening_id');
    }

    /**
     * REQ-RPT-007 -- nominal export columns (map key 'Screening'). Every
     * closure reads ONLY the relations eager-loaded by
     * ReportController::REPORT_MODEL_MAP's `with` array (the N+1 firewall,
     * design D9) -- never `visit.practitioner.user` (not in that array).
     */
    public function reportColumns(): array
    {
        return [
            'Niño' => fn ($r) => trim(($r->child->first_name ?? '').' '.($r->child->last_name_paternal ?? '').' '.($r->child->last_name_maternal ?? '')),
            'CURP' => fn ($r) => $r->child->curp ?? '',
            'Sexo' => fn ($r) => $r->child->sex?->name ?? '',
            'Edad cronológica (días)' => fn ($r) => $r->chronological_age_days,
            'Edad corregida (días)' => fn ($r) => $r->corrected_age_days,
            'Centro de salud' => fn ($r) => $r->visit->health_center?->name ?? '',
            'Folio de visita' => fn ($r) => $r->visit->receipt_number ?? '',
            'Fecha de visita' => fn ($r) => $r->visit->visit_date ? $r->visit->visit_date->format('d/m/Y') : '',
            'Grupo aplicado' => fn ($r) => $r->applied_age_group?->name ?? '',
            'MG' => fn ($r) => self::axisColorName($r, 'MG'),
            'MF' => fn ($r) => self::axisColorName($r, 'MF'),
            'LE' => fn ($r) => self::axisColorName($r, 'LE'),
            'SO' => fn ($r) => self::axisColorName($r, 'SO'),
            'CO' => fn ($r) => self::axisColorName($r, 'CO'),
            'EN' => fn ($r) => self::axisColorName($r, 'EN'),
            'ALA' => fn ($r) => self::axisColorName($r, 'ALA'),
            'ALE' => fn ($r) => self::axisColorName($r, 'ALE'),
            'Color global' => fn ($r) => $r->result_color?->name ?? '',
            'Situación' => fn ($r) => $r->situation?->name ?? '',
            'Estado' => fn ($r) => $r->screening_status?->name ?? '',
            'Señales de alerta' => fn ($r) => $r->alert_sign_count,
            'Factores de riesgo' => fn ($r) => $r->risk_factor_count,
            'Matrícula del aplicador' => fn ($r) => $r->visit->practitioner?->license_number ?? '',
        ];
    }

    private static function axisColorName(self $screening, string $areaCode): string
    {
        $result = $screening->area_results->first(
            fn (ScreeningAreaResult $ar) => $ar->development_area?->code === $areaCode
        );

        return $result?->result_color?->name ?? '';
    }
}
