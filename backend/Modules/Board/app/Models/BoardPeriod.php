<?php

namespace Modules\Board\Models;

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
class BoardPeriod extends BaseModel implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;

    protected $table = 'board_period';

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'board_period_status_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function board_period_status(): BelongsTo
    {
        return $this->belongsTo(BoardPeriodStatus::class, 'board_period_status_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(BoardMember::class, 'board_period_id');
    }

    public function isCurrent(): bool
    {
        return $this->board_period_status_id === BoardPeriodStatus::CURRENT;
    }

    /**
     * Register media collections for board periods.
     * - acta: single PDF (constitutive act or meeting minutes).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('acta')
            ->acceptsMimeTypes(['application/pdf'])
            ->singleFile();
    }
}
