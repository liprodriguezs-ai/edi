<?php

namespace Modules\ChildRecord\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\ChildRecord\Database\Factories\ChildFactory;
use Modules\Clinic\Models\HealthCenter;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Sensitive PII of a minor (REQ-XC-005). Audited in full (old/new values live
 * in `audits`, access-gated by read.audit) — never excluded via
 * $auditExclude, since the audit trail itself is the required control here.
 */
#[ObservedBy([GlobalObserver::class])]
class Child extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'child';

    protected $fillable = [
        'first_name',
        'last_name_paternal',
        'last_name_maternal',
        'birth_date',
        'sex_id',
        'curp',
        'curp_mismatch',
        'external_id',
        'health_center_id',
        'is_schooled',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'is_schooled' => 'boolean',
            'curp_mismatch' => 'boolean',
        ];
    }

    public function sex(): BelongsTo
    {
        return $this->belongsTo(Sex::class, 'sex_id');
    }

    public function health_center(): BelongsTo
    {
        return $this->belongsTo(HealthCenter::class, 'health_center_id');
    }

    public function birth_record(): HasOne
    {
        return $this->hasOne(BirthRecord::class, 'child_id');
    }

    public function child_caregivers(): HasMany
    {
        return $this->hasMany(ChildCaregiver::class, 'child_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class, 'child_id');
    }

    protected static function newFactory(): ChildFactory
    {
        return ChildFactory::new();
    }

    /**
     * REQ-RPT-007 -- registry export columns (map key 'Child'). Reads ONLY
     * the eager-loaded `sex`/`health_center` relations (design D9).
     */
    public function reportColumns(): array
    {
        return [
            'Nombre(s)' => fn ($r) => $r->first_name,
            'Apellido paterno' => fn ($r) => $r->last_name_paternal,
            'Apellido materno' => fn ($r) => $r->last_name_maternal,
            'CURP' => fn ($r) => $r->curp ?? '',
            'Fecha de nacimiento' => fn ($r) => $r->birth_date ? $r->birth_date->format('d/m/Y') : '',
            'Sexo' => fn ($r) => $r->sex?->name ?? '',
            'Centro de salud' => fn ($r) => $r->health_center?->name ?? '',
            'Escolarizado' => fn ($r) => $r->is_schooled ? 'Sí' : 'No',
            'F. registro' => fn ($r) => $r->created_at ? $r->created_at->format('d/m/Y') : '',
        ];
    }
}
