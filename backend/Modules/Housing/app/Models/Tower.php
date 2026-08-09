<?php

namespace Modules\Housing\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Housing\Database\Factories\TowerFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Tower extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'tower';

    protected $fillable = [
        'name',
        'floors_count',
        'section_id',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'floors_count' => 'integer',
            'order' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'tower_id');
    }

    /**
     * Returns true if this tower has at least one non-deleted unit.
     */
    public function hasUnits(): bool
    {
        return $this->units()->exists();
    }

    protected static function newFactory(): TowerFactory
    {
        return TowerFactory::new();
    }
}
