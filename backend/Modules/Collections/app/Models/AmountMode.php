<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class AmountMode extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'amount_mode';

    public const FIXED_REQUIRED = '502ed9b1-befd-4d9e-87b0-37b42049646c';

    public const FIXED_OPTIONAL = '17eba951-dbfb-4662-9c18-f3b80f390001';

    public const RANGE = 'd02597fe-e700-4a86-a979-7970ad647fe7';

    public const FREE = '494676a2-3f68-4d90-a690-673c2f9c0623';

    public const BY_SHARE = '4918da56-6347-468c-8745-7d5d87c042d8';

    protected $fillable = [
        'id',
        'name',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }
}
