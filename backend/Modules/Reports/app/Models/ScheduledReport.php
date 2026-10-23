<?php

namespace Modules\Reports\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * A saved report the nightly runner regenerates on a cadence (REQ-RPT-013).
 *
 * Tenant-scoped: a schedule belongs to the organization whose data it exports,
 * and the runner switches into that tenant before generating so the report can
 * never span organizations.
 */
#[ObservedBy([GlobalObserver::class])]
class ScheduledReport extends BaseModel
{
    use BelongsToTenant;

    protected $table = 'scheduled_report';

    protected $fillable = [
        'name',
        'report_model_key',
        'params',
        'report_frequency_id',
        'recipient_user_id',
        'is_active',
    ];

    /**
     * last_run_at is deliberately NOT fillable: it is the idempotency anchor the
     * runner stamps, and a client that could set it could force or suppress a run.
     */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    /** @Relation */
    public function report_frequency(): BelongsTo
    {
        return $this->belongsTo(ReportFrequency::class, 'report_frequency_id');
    }

    /** @Relation */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
