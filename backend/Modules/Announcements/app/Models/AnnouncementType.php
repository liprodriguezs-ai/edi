<?php

namespace Modules\Announcements\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\Concerns\HasCatalogKey;
use QCH\Core\Models\GlobalObserver;
use QCH\Core\Models\TenantCatalogModel;

#[ObservedBy([GlobalObserver::class])]
class AnnouncementType extends TenantCatalogModel
{
    use HasCatalogKey;
    use HasFactory;

    protected $table = 'announcement_type';

    /**
     * Keys de las filas de sistema (defaults de avisos auto-creados); se
     * resuelven por (tenant, key) vía HasCatalogKey, nunca por UUID.
     */
    public const KEY_GENERAL = 'general';

    public const KEY_FINANCIAL = 'financial';

    protected $fillable = [
        'name',
        'icon',
        'order',
        'is_active',
        'key',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }
}
