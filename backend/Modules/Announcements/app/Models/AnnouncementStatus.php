<?php

namespace Modules\Announcements\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class AnnouncementStatus extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'announcement_status';

    public const DRAFT = 'ef346a2a-78c3-4d1d-9b7a-bd7245b808df';

    public const PUBLISHED = '03df32bb-0c89-4fc4-90e7-f15e12edf62a';

    public const ARCHIVED = 'fbb30570-671e-4d7b-a977-d7ae8bc50dbe';

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
