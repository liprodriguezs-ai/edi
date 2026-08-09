<?php

namespace Modules\Reports\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * GeneratedReport — stores async export requests and their results.
 *
 * Status lifecycle: pending → processing → ready | failed.
 * Rows older than `expires_at` (90 days default) are pruned by reports:prune-expired.
 */
#[ObservedBy([GlobalObserver::class])]
class GeneratedReport extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_READY,
        self::STATUS_FAILED,
    ];

    protected $table = 'generated_report';

    protected $fillable = [
        'report_key',
        'format',
        'config',
        'status',
        'file_path',
        'generated_by_user_id',
        'expires_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The user who requested the report.
     * User is central (no tenant scope), so we strip UserTenantScope.
     */
    public function generated_by_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }
}
