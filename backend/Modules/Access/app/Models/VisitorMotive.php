<?php

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\GlobalObserver;
use QCH\Core\Models\TenantCatalogModel;

#[ObservedBy([GlobalObserver::class])]
class VisitorMotive extends TenantCatalogModel
{
    use HasFactory;

    protected $table = 'visitor_motive';

    protected $fillable = [
        'name',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
