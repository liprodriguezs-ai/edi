<?php

namespace Modules\Chat\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use App\Support\MediaAttachmentPolicy;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class ChatMessage extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    protected $table = 'chat_message';

    /**
     * user_id (author), hidden (moderation flag) and edited_at (server stamp)
     * are deliberately NOT mass-assignable: the author is always forced from
     * the authenticated user, hidden only changes via moderation hide/unhide,
     * and edited_at is stamped server-side on author edits.
     */
    protected $fillable = [
        'chat_channel_id',
        'body',
        'reply_to_id',
    ];

    protected function casts(): array
    {
        return [
            'hidden' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(ChatChannel::class, 'chat_channel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'reply_to_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatReaction::class, 'chat_message_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ChatMessageReport::class, 'chat_message_id');
    }

    /**
     * File attachments — generalized to the platform-wide whitelist shared with
     * Announcements (chat-file-attachments, design ADR-1). MUST declare the FULL
     * `MediaAttachmentPolicy::MIME_TYPES` set (1:1 with the extension whitelist):
     * a narrower collection than the FormRequest/service whitelist throws at
     * `addMedia()`/`copy()` for a file already accepted upstream (the exact drift
     * Announcements has today — do not reproduce it here).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->acceptsMimeTypes(array_values(MediaAttachmentPolicy::MIME_TYPES));
    }

    /**
     * The WebP conversion is image-scoped (ADR-4): Spatie regenerates
     * conversions on BOTH `addMedia()` and `copy()` (the announcement
     * cross-post path — ADR-3), so a non-image mime (PDF, Office doc, audio,
     * video) must bail out here or it would be rasterized into a bogus "webp
     * thumbnail" of a document.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        if (! str_starts_with((string) $media?->mime_type, 'image/')) {
            return;
        }

        $this->addMediaConversion('webp')
            ->format('webp')
            ->quality(80)
            ->nonQueued();
    }
}
