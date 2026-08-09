<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class ChatChannel extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'chat_channel';

    /**
     * Target types an admin may assign to a channel. Same UNIT-based vocabulary as
     * charges (Collections TargetResolver::TARGET_TYPES) so chat, charges and
     * announcements share ONE target system, plus 'board' (mesa directiva).
     * Excludes 'by_share' (a charge-proration concept with no chat meaning — the
     * service still tolerates it defensively) and 'direct' (1:1 channels are
     * system-created via openDirectChannel, never through the admin form).
     *
     * @var string[]
     */
    public const ADMIN_TARGET_TYPES = ['all', 'by_type', 'by_tower', 'by_floor', 'by_feature', 'single_unit', 'manual', 'by_section', 'with_vehicles', 'board'];

    protected $fillable = [
        'name',
        'chat_channel_type_id',
        'icon',
        'description',
        'target_type',
        'target_selector',
        'write_target_type',
        'write_target_selector',
        'is_moderated',
        'is_system',
        'is_archived',
        'notify_on_post',
    ];

    protected function casts(): array
    {
        return [
            'target_selector' => 'array',
            'write_target_selector' => 'array',
            'is_moderated' => 'boolean',
            'is_system' => 'boolean',
            'is_archived' => 'boolean',
            'notify_on_post' => 'boolean',
        ];
    }

    public function channelType(): BelongsTo
    {
        return $this->belongsTo(ChatChannelType::class, 'chat_channel_type_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChatChannelMember::class, 'chat_channel_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chat_channel_id');
    }
}
