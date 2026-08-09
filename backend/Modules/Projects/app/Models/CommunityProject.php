<?php

namespace Modules\Projects\Models;

use App\Models\Scopes\UserTenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Collections\Models\AmountMode;
use QCH\Core\Casts\TenantMoneyCast;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class CommunityProject extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    protected $table = 'community_project';

    protected $fillable = [
        'title',
        'description',
        'community_project_category_id',
        'participation_type_id',
        'amount_mode_id',
        'fixed_amount',
        'min_amount',
        'ideal_amount',
        'max_amount',
        'allow_decline',
        'target_type',
        'target_selector',
        'goal_amount',
        'start_date',
        'due_date',
        'responsible_user_id',
        'visible_to_residents',
        'allow_comments',
        'payments_visible_to_residents',
        'community_project_status_id',
    ];

    protected function casts(): array
    {
        return [
            'fixed_amount' => TenantMoneyCast::class,
            'min_amount' => TenantMoneyCast::class,
            'ideal_amount' => TenantMoneyCast::class,
            'max_amount' => TenantMoneyCast::class,
            'goal_amount' => TenantMoneyCast::class,
            'allow_decline' => 'boolean',
            'target_selector' => 'array',
            'start_date' => 'date',
            'due_date' => 'date',
            'visible_to_residents' => 'boolean',
            'allow_comments' => 'boolean',
            'payments_visible_to_residents' => 'boolean',
        ];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(CommunityProjectStatus::class, 'community_project_status_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CommunityProjectCategory::class, 'community_project_category_id');
    }

    public function participationType(): BelongsTo
    {
        return $this->belongsTo(ParticipationType::class, 'participation_type_id');
    }

    public function amount_mode(): BelongsTo
    {
        return $this->belongsTo(AmountMode::class, 'amount_mode_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id')
            ->withoutGlobalScope(UserTenantScope::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(ProjectContribution::class, 'community_project_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class, 'community_project_id');
    }

    /**
     * Register media collections for community projects.
     * - cover: single image (cover photo).
     * - documents: up to 5 quotation/document files (limit enforced in the service).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->singleFile();

        $this->addMediaCollection('documents')
            ->acceptsMimeTypes([
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'image/jpeg',
                'image/png',
            ]);
    }

    public function reportColumns(): array
    {
        return [
            'Título' => function ($result) {
                return $result->title;
            },
            'Categoría' => function ($result) {
                return $result->category?->name;
            },
            'Tipo de participación' => function ($result) {
                return $result->participationType?->name;
            },
            'Monto fijo' => function ($result) {
                return $result->fixed_amount ? (float) $result->fixed_amount->formatByDecimal() : 0;
            },
            'Monto mín.' => function ($result) {
                return $result->min_amount ? (float) $result->min_amount->formatByDecimal() : 0;
            },
            'Monto ideal' => function ($result) {
                return $result->ideal_amount ? (float) $result->ideal_amount->formatByDecimal() : 0;
            },
            'Monto máx.' => function ($result) {
                return $result->max_amount ? (float) $result->max_amount->formatByDecimal() : 0;
            },
            'Meta' => function ($result) {
                return $result->goal_amount ? (float) $result->goal_amount->formatByDecimal() : 0;
            },
            'F. inicio' => function ($result) {
                return $result->start_date ? $result->start_date->format('d/m/Y') : '';
            },
            'F. límite' => function ($result) {
                return $result->due_date ? $result->due_date->format('d/m/Y') : '';
            },
            'Responsable' => function ($result) {
                return $result->responsibleUser ? trim($result->responsibleUser->name.' '.$result->responsibleUser->last_name.' '.($result->responsibleUser->second_last_name ?? '')) : '';
            },
            'Estado' => function ($result) {
                return $result->status?->name;
            },
            'F. creación' => function ($result) {
                return $result->created_at ? $result->created_at->format('d/m/Y') : '';
            },
        ];
    }
}
