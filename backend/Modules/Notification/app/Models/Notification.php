<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

// use Modules\Notification\Database\Factories\NotificationFactory;
#[ObservedBy([GlobalObserver::class])]
class Notification extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;
    // use \QCH\Core\Models\Country;

    public const REFER_WELCOME = '3050b9df-7623-4698-80a8-0e9959a84585';

    public const ORDER_DELETED_REQUEST = '94e68050-b251-4066-b2b2-6950348f55d5';

    public const USER_DELETED_REQUEST = '18640bf7-90a9-4114-9240-5285a2180699';

    public const USER_DELETED = 'f602db44-08f3-46de-8a58-1c78ff09bf0d';

    public const ORDER_DELETED = '9f62f3d2-2c3f-46d4-9c90-8185867ac7cd';

    protected $table = 'notification';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'title',
        'message',
        'status',
        'click_action',
        'repeat',
        'date',
    ];

    public function notification_user()
    {
        return $this->hasMany(NotificationUser::class);
    }
}
