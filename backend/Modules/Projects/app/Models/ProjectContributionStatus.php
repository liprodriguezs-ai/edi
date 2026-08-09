<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class ProjectContributionStatus extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'project_contribution_status';

    public const PENDING = 'f145292c-6de1-4d38-8b96-89c793374784';

    public const PARTIAL = 'defd8a59-79e6-4930-9b82-623346530209';

    public const IDEAL = '54099f12-2680-4c81-a648-067a02c183f9';

    public const GENEROUS = '8beef447-21b1-406a-9118-1266ab18cc6b';

    public const BELOW_MIN = 'b94c78d1-5aeb-4d35-8d46-32a0db8e5148';

    public const DECLINED = 'bdd98e40-c08d-40ad-af46-ac01f9b99b1f';

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
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }
}
