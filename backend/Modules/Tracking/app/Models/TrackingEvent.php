<?php

namespace Modules\Tracking\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * TrackingEvent — lightweight audit log of what TrackingDispatcher sent to
 * each channel. One row per (event_id, channel): a Purchase dispatched to
 * both Facebook and GA4 produces two rows. Written by DeliverTrackingEventJob
 * so conversions can be debugged (what was sent, the channel's response,
 * whether it deduplicated).
 *
 * Central (no tenant_id): tracking events can occur before a tenant exists
 * (anonymous landing visitor, in-flight registration).
 *
 * NOT to be confused with Modules\Tracking\DTO\TrackingEvent (the in-memory
 * value object passed to channels) — same name, different namespace, by design.
 */
#[ObservedBy([GlobalObserver::class])]
class TrackingEvent extends BaseModel
{
    use CentralConnection;
    use HasFactory;
    use MassPrunable;

    /** @var array<int, string> */
    public const STATUSES = [self::STATUS_QUEUED, self::STATUS_SENT, self::STATUS_FAILED];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $table = 'tracking_event';

    protected $fillable = [
        'event_name',
        'event_id',
        'channel',
        'status',
        'request_payload',
        'response_snippet',
    ];

    protected $casts = [
        'request_payload' => 'array',
    ];

    /**
     * Rows older than `tracking.retention_days` (env `TRACKING_RETENTION_DAYS`,
     * default 90) are candidates for the daily `model:prune` sweep registered in
     * routes/console.php. BaseModel enables SoftDeletes on every model, but
     * MassPrunable detects that (Model::isSoftDeletable()) and calls
     * forceDelete() instead of a soft delete — the right semantics for a debug
     * log that must not accumulate forever.
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('tracking.retention_days', 90)));
    }
}
