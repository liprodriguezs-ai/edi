<?php

namespace Modules\Stimulation\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Stimulation\Database\Factories\SongFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Tenant-scoped, admin-editable catalog of songs. Feeds combobox suggestions
 * only (D3) — the session stores what was actually used as free text, never
 * a FK to this table.
 */
#[ObservedBy([GlobalObserver::class])]
class Song extends BaseModel
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'song';

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): SongFactory
    {
        return SongFactory::new();
    }
}
