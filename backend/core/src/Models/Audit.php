<?php

namespace QCH\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Observers\AuditObserver;

/**
 * Audit model. When save runs inside a DB transaction, it is deferred to afterCommit
 * so the insert never runs in the same transaction as the audited model (avoids
 * "current transaction is aborted" when something in that transaction fails).
 */
#[ObservedBy([AuditObserver::class])]
class Audit extends \OwenIt\Auditing\Models\Audit
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function save(array $options = []): bool
    {
        $this->stampTenantId();

        if (DB::transactionLevel() > 0) {
            $attributes = $this->getAttributes();
            $connectionName = $this->getConnectionName() ?? DB::getDefaultConnection();
            DB::afterCommit(function () use ($attributes, $connectionName): void {
                $model = new static;
                $model->setConnection($connectionName);
                $model->forceFill($attributes)->saveQuietly();
            });
            $this->exists = true;

            return true;
        }

        return parent::save($options);
    }

    /**
     * Stamp the current tenant id on the audit row so audit-log reports can be
     * scoped per tenant. Single-database tenancy: the `audits` table is shared,
     * isolation is row-level via this nullable column. No-op when tenancy is not
     * initialized (central/super-admin writes) or the column is already set.
     */
    private function stampTenantId(): void
    {
        if (! empty($this->getAttribute('tenant_id'))) {
            return;
        }

        if (function_exists('tenancy') && tenancy()->initialized && tenant()) {
            $this->setAttribute('tenant_id', tenant()->getTenantKey());
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
