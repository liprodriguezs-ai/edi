<?php

namespace Modules\Announcements\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Announcement extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    protected $table = 'announcement';

    protected $fillable = [
        'title',
        'announcement_type_id',
        'announcement_priority_id',
        'announcement_status_id',
        'content',
        'target_type',
        'target_selector',
        'channel_push',
        'channel_board',
        'channel_email',
        'channel_sms',
        'publish_at',
        'expires_at',
        'edited_at',
        'author_user_id',
    ];

    protected function casts(): array
    {
        return [
            'target_selector' => 'array',
            'channel_push' => 'boolean',
            'channel_board' => 'boolean',
            'channel_email' => 'boolean',
            'channel_sms' => 'boolean',
            'publish_at' => 'datetime',
            'expires_at' => 'date',
            'edited_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AnnouncementType::class, 'announcement_type_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(AnnouncementPriority::class, 'announcement_priority_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(AnnouncementStatus::class, 'announcement_status_id');
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class, 'announcement_id');
    }

    /**
     * Register media collections for announcements.
     * Up to 5 attachments (images, documents, etc.), max 10 MB each — the limit
     * is enforced in AnnouncementService::attachFile (a 6th file is rejected with
     * 422; onlyKeepLatest would silently DELETE the oldest official document).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->acceptsMimeTypes([
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]);
    }
}
