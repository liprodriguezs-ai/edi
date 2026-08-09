<?php

namespace Modules\SubscriptionPlan\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Module\Models\Module;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Central sellable module/feature (billing v2). A billable module groups one or
 * more platform `module` rows (via billable_module_system_module) and is priced
 * per plan in plan_module_price. No tenant_id: the catalog is identical for
 * every tenant (mirrors BillingPackage).
 */
#[ObservedBy([GlobalObserver::class])]
class BillableModule extends BaseModel
{
    use CentralConnection;

    protected $table = 'billable_module';

    /**
     * The only sellable module since the 2026-07 collapse: `package` and
     * `amenities` were folded into it and soft-deleted.
     */
    public const KEY_BASE = 'base';

    protected $fillable = [
        'key',
        'name',
        'description',
        'features',
        'sort_order',
        'is_active',
        'is_base',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_base' => 'boolean',
            'features' => 'array',
        ];
    }

    /**
     * Platform modules this billable module grants access to.
     */
    public function systemModules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'billable_module_system_module')
            ->withTimestamps();
    }

    /**
     * Per-plan prices of this billable module (billing_package × interval).
     */
    public function plan_prices(): HasMany
    {
        return $this->hasMany(PlanModulePrice::class);
    }

    /**
     * Per-plan offering facts of this billable module (is_free/trial_days),
     * independent of interval. See PlanModule.
     */
    public function plan_modules(): HasMany
    {
        return $this->hasMany(PlanModule::class);
    }
}
