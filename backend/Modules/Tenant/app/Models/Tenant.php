<?php

namespace Modules\Tenant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\SubscriptionPlan\Models\BillingPackage;
use Modules\System\Models\System;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use QCH\Core\Models\Role;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Tenant model as per stancl/tenancy docs: extend BaseTenant, add HasDatabase, implement TenantWithDatabase.
 *
 * Auditable (§16.4 r5): operational configuration changes (timezone, currency,
 * type, subscription_plan_id, status, …) must land in the `audits`
 * table with before/after values. Tenant does not extend BaseModel (it extends
 * Stancl's BaseTenant), so the owen-it contract + trait are added here
 * explicitly. The Stancl VirtualColumn `data` payload and the legacy
 * `tenancy_db_name` are excluded — only real custom columns are audited.
 *
 * @see https://tenancyforlaravel.com/docs/v3/tenants
 */
class Tenant extends BaseTenant implements AuditableContract, TenantWithDatabase
{
    use Auditable;
    use HasDatabase;
    use SoftDeletes;

    /** @var array<int, string> */
    protected $auditExclude = ['data', 'tenancy_db_name'];

    public const STATUS_ACTIVO = 'activo';

    public const STATUS_PENDIENTE_PAGO = 'pendiente_pago';

    public const STATUS_BLOQUEADO = 'bloqueado';

    public const STATUSES = [self::STATUS_ACTIVO, self::STATUS_PENDIENTE_PAGO, self::STATUS_BLOQUEADO];

    public const PAYMENT_METHOD_CARD = 'card';

    public const PAYMENT_METHOD_TRANSFER = 'transfer';

    public const PAYMENT_METHOD_CASH = 'cash';

    public const PAYMENT_METHODS = [
        self::PAYMENT_METHOD_CARD,
        self::PAYMENT_METHOD_TRANSFER,
        self::PAYMENT_METHOD_CASH,
    ];

    const DEFAULT = '38f1b217-db74-4aa9-9f7d-a421985f389c';

    protected $table = 'tenant';

    protected $fillable = ['id', 'name', 'status', 'tenancy_db_name', 'tenant_payment_type_id', 'billing_package_id', 'payment_method', 'timezone', 'currency', 'type', 'uses_ownership_share'];

    protected $hidden = ['data'];

    protected function casts(): array
    {
        return [
            'uses_ownership_share' => 'boolean',
        ];
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'status',
            'tenancy_db_name',
            'tenant_payment_type_id',
            'billing_package_id',
            'payment_method',
            'timezone',
            'currency',
            'type',
            'uses_ownership_share',
            'data',
            'tenant_id',
            'created_at',
            'updated_at',
            'deleted_at',
            'user_created_id',
            'user_updated_id',
            'user_deleted_id',
            'user_restored_id',
        ];
    }

    /**
     * Users that belong to this tenant (many-to-many via user_tenant pivot).
     */
    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'user_tenant',
            'tenant_id',
            'user_id'
        )->withTimestamps();
    }

    public function payment_type()
    {
        return $this->belongsTo(TenantPaymentType::class, 'tenant_payment_type_id');
    }

    /**
     * All systems assigned to this tenant (via system_tenant pivot).
     */
    public function systems()
    {
        return $this->belongsToMany(
            System::class,
            'system_tenant',
            'tenant_id',
            'system_id'
        )->withPivot('is_addon')->withTimestamps();
    }

    /**
     * Only active systems assigned to this tenant.
     */
    public function active_systems()
    {
        return $this->systems()->where('system.is_active', true);
    }

    /**
     * Billing v2 plan (billing_package: basico..premium) the cluster is on.
     */
    public function billing_package()
    {
        return $this->belongsTo(BillingPackage::class, 'billing_package_id');
    }

    public function owners()
    {
        return $this->belongsToMany(
            User::class,
            'user_tenant',
            'tenant_id',
            'user_id'
        )
            ->whereHas('roles', function ($query) {
                $query->where('uuid', Role::ACCOUNT_OWNER);
            })
            ->with('roles')
            ->withTimestamps();
    }
}
