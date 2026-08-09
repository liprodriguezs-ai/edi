<?php

namespace Modules\Housing\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Housing\Database\Factories\SectionFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[ObservedBy([GlobalObserver::class])]
class Section extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'section';

    protected $fillable = [
        'name',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    public function towers(): HasMany
    {
        return $this->hasMany(Tower::class, 'section_id');
    }

    protected static function newFactory(): SectionFactory
    {
        return SectionFactory::new();
    }
}
