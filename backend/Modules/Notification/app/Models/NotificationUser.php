<?php

namespace Modules\Notification\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

// use Modules\Notification\Database\Factories\NotificationUserFactory;
#[ObservedBy([GlobalObserver::class])]
class NotificationUser extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'notification_user';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'is_read',
        'user_id',
        'notification_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }
}
