<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class ChargeConcept extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'charge_concept';

    protected $fillable = [
        'code',
        'name',
        'description',
        'charge_concept_category_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** Stable English code of the generic technical fallback concept (free-text "Otro"). */
    public const CODE_OTHER = 'OTHER';

    /**
     * Concepts whose `code` the backend resolves at RUNTIME and must never be
     * deleted out from under a feature. `OTHER` is the fallback used by the cobro
     * directo free-text motive (DirectChargeService::resolveOtherConceptId); losing
     * it would 422 every free-text direct charge. DOMAIN concepts are created by their
     * source modules (Amenities, fines, …), never pre-seeded, and the admin may freely
     * remove them — only OTHER is protected.
     *
     * @var array<int, string>
     */
    public const PROTECTED_CODES = [self::CODE_OTHER];

    protected static function booted(): void
    {
        static::deleting(function (self $concept): void {
            if (in_array($concept->code, self::PROTECTED_CODES, true)) {
                throw ValidationException::withMessages([
                    'id' => ['Este concepto es de sistema y no puede eliminarse.'],
                ]);
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ChargeConceptCategory::class, 'charge_concept_category_id');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(ChargePolicy::class, 'charge_concept_id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'charge_concept_id');
    }
}
