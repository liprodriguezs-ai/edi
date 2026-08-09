<?php

namespace Modules\Projects\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class CommunityProjectStatus extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'community_project_status';

    public const DRAFT = 'ccc0f0f1-ddea-4bb1-b793-05205a67835e';

    public const ACTIVE = 'cf692500-db9b-40da-b441-8fec9f546bf8';

    public const FULLY_FUNDED = 'f28b7545-56eb-4080-93cf-f51d56c80046';

    public const EXECUTED = 'bd6182b6-83ce-40c6-8f97-030cb5dd311a';

    public const CANCELLED = '5e179ecd-221b-454b-9cfd-a3ca2192a54d';

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
