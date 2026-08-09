<?php

namespace Modules\Complaint\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Housing\Models\Unit;
use Modules\Housing\Models\UnitUser;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Complaint extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use InteractsWithMedia;

    protected $table = 'complaint';

    protected $fillable = [
        'case_number',
        'title',
        'complaint_category_id',
        'complaint_priority_id',
        'complaint_status_id',
        'reporter_user_id',
        'assigned_user_id',
        'against_unit_id',
        'against_description',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('evidence')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ComplaintCategory::class, 'complaint_category_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(ComplaintPriority::class, 'complaint_priority_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ComplaintStatus::class, 'complaint_status_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function against_unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'against_unit_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ComplaintMessage::class, 'complaint_id')->orderBy('created_at');
    }

    /**
     * Confidential exclusion (ADR-4, spec "Confidential Exclusion by Unit Linkage").
     *
     * Hides complaints whose against_unit_id is a unit the caller actively belongs
     * to (unit_user without end_date), UNLESS the caller is the reporter — a
     * reporter always sees their own filing. When against_unit_id is null, no
     * automatic exclusion applies (documented v1 limitation).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $excludedUnitIds = UnitUser::where('user_id', $user->id)
            ->whereNull('end_date')
            ->pluck('unit_id')
            ->all();

        if ($excludedUnitIds === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($excludedUnitIds, $user) {
            $q->whereNull('against_unit_id')
                ->orWhereNotIn('against_unit_id', $excludedUnitIds)
                ->orWhere('reporter_user_id', $user->id);
        });
    }
}
