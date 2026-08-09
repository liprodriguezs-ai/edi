<?php

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class VisitorStatus extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'visitor_status';

    public const PENDING = '3f89e1ae-ad90-4b4c-9ed8-b434da6512d7';

    public const USED = 'be5a6191-8355-42b2-b5ee-015a93440dea';

    public const CANCELLED = '1bbfd0c8-4316-46e2-b7ca-22b63881e16f';

    public const EXPIRED = '849bd1cf-144c-4f14-8560-6e59e06742d4';

    public const PENDING_APPROVAL = '0f894a5e-3aac-4379-bf42-7e4aba0b6e3b';

    public const APPROVED = '4b78d5c6-619a-402c-9555-b010dec02542';

    public const REJECTED = '1c63562d-ebd6-4910-8529-34956e25ac0b';

    protected $fillable = [
        'id',
        'name',
        'color',
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
