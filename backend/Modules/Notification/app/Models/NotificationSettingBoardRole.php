<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Board\Models\BoardRole;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Pivot between NotificationSetting and BoardRole.
 *
 * Records which board roles should receive a given notification event
 * within a cluster. Only meaningful for events with audience=board
 * (e.g. payment_registered). Tenant-scoped: BelongsToTenant stamps
 * tenant_id on creation so TenantScope applies automatically.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $notification_setting_id
 * @property string $board_role_id
 */
#[ObservedBy([GlobalObserver::class])]
class NotificationSettingBoardRole extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'notification_setting_board_role';

    protected $fillable = [
        'notification_setting_id',
        'board_role_id',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * The notification setting this pivot belongs to.
     */
    public function notification_setting(): BelongsTo
    {
        return $this->belongsTo(NotificationSetting::class, 'notification_setting_id');
    }

    /**
     * The board role that receives the notification.
     */
    public function board_role(): BelongsTo
    {
        return $this->belongsTo(BoardRole::class, 'board_role_id');
    }
}
