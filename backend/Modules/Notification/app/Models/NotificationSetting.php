<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Per-organization configuration for a single notification event.
 *
 * One row exists per (tenant, notification_event) pair, seeded by
 * TenantNotificationSettingSeeder when the organization is created. The admin
 * can toggle channels and enable/disable the event from the Notifications
 * tab in the Organization Configuration page.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $notification_event_id
 * @property bool $is_active
 * @property bool $channel_email
 * @property bool $channel_push
 * @property bool $channel_whatsapp
 */
#[ObservedBy([GlobalObserver::class])]
class NotificationSetting extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'notification_setting';

    protected $fillable = [
        'notification_event_id',
        'is_active',
        'channel_email',
        'channel_push',
        'channel_whatsapp',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'channel_email' => 'boolean',
            'channel_push' => 'boolean',
            'channel_whatsapp' => 'boolean',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * The central notification event this setting configures.
     */
    public function notification_event(): BelongsTo
    {
        return $this->belongsTo(NotificationEvent::class, 'notification_event_id');
    }

}
