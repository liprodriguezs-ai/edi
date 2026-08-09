<?php

namespace Modules\Announcements\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class AnnouncementPriority extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'announcement_priority';

    public const NORMAL = '5feb9493-65b9-4131-980a-8b1f80bcb501';

    public const IMPORTANT = '3c8b20a0-7b63-4741-8777-b9f73262d363';

    public const URGENT = '829a2bfd-af51-4f9d-a2fd-97ec7be89712';

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
