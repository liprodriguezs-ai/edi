<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * ChargePolicy — the single point of charge configuration for a concept.
 *
 * One concept can own several policies (1:N); the partial unique index keeps at
 * most one ACTIVE policy per (tenant, concept, periodicity).
 *
 * The collection MODE is NOT stored — it is intrinsic to the periodicity:
 *   - PERIODIC (periodicity.is_automatic = true): valid_from/valid_to define the active
 *     window; the daily cron generates batches automatically while the window covers today.
 *   - DIRECT (periodicity.is_automatic = false, i.e. EVENTUAL): one-shot. valid_from/valid_to
 *     are null; a batch + charges are created immediately on policy creation. No cron.
 *
 * Use {@see ChargePolicy::isDirect()} / {@see ChargePolicy::isPeriodic()} to branch on it.
 */
#[ObservedBy([GlobalObserver::class])]
class ChargePolicy extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'charge_policy';

    protected $fillable = [
        'charge_concept_id',
        'name',
        'amount_mode_id',
        'fixed_amount',
        'min_amount',
        'ideal_amount',
        'max_amount',
        'prorate_total_amount',
        'grace_days',
        'due_soon_days',
        'periodicity_id',
        'valid_from',
        'valid_to',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fixed_amount' => TenantMoneyCast::class,
            'min_amount' => TenantMoneyCast::class,
            'ideal_amount' => TenantMoneyCast::class,
            'max_amount' => TenantMoneyCast::class,
            'prorate_total_amount' => TenantMoneyCast::class,
            'is_active' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'grace_days' => 'integer',
            'due_soon_days' => 'integer',
        ];
    }

    /**
     * @Relation
     *
     * Eager-loadable via /selects `_with` (the charge-batch form posts
     * `_with=concept,periodicity`). The @Relation annotation is required by the
     * marcelgwerder/laravel-api-handler whitelist — without it, _with throws
     * UnknownResourceRelation (HTTP 400).
     */
    public function concept(): BelongsTo
    {
        return $this->belongsTo(ChargeConcept::class, 'charge_concept_id');
    }

    public function amount_mode(): BelongsTo
    {
        return $this->belongsTo(AmountMode::class, 'amount_mode_id');
    }

    /**
     * @Relation
     *
     * Eager-loadable via /selects `_with` (the charge-batch form posts
     * `_with=concept,periodicity`). The @Relation annotation is required by the
     * marcelgwerder/laravel-api-handler whitelist — without it, _with throws
     * UnknownResourceRelation (HTTP 400).
     */
    public function periodicity(): BelongsTo
    {
        return $this->belongsTo(Periodicity::class, 'periodicity_id');
    }

    public function charge_batches(): HasMany
    {
        return $this->hasMany(ChargeBatch::class, 'charge_policy_id');
    }

    /**
     * DIRECT (one-shot) policy: its periodicity is non-automatic (EVENTUAL is the only one).
     * The batch + charges are generated immediately on creation; the cron never touches it.
     */
    public function isDirect(): bool
    {
        return $this->periodicity_id === Periodicity::EVENTUAL;
    }

    /**
     * PERIODIC (cron-driven) policy: its periodicity is automatic. The daily cron clones
     * the initial template batch for every period while [valid_from, valid_to] covers today.
     */
    public function isPeriodic(): bool
    {
        return ! $this->isDirect();
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'charge_policy_id');
    }

    /**
     * Late-fee (recargo moratorio) escalation rules of this policy (1:N).
     * Consumed by ChargeStatusService::accrueSurcharge to derive charge.surcharge_amount.
     */
    public function surcharge_rules(): HasMany
    {
        return $this->hasMany(ChargeSurchargeRule::class, 'charge_policy_id');
    }
}
