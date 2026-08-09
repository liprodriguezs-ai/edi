<?php

namespace Modules\Chat\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class ChatChannelType extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'chat_channel_type';

    // Central catalog (no tenant_id) — shared UUID constants seeded by CatalogSeeder
    public const OFFICIAL = 'fc4216b1-83a3-4dd6-ad7c-ced4f307143a';

    public const GENERAL = '02026941-2e75-4276-ab9f-e0a3a955d92a';

    public const CUSTOM = '6d754528-cc5c-4dae-b96a-ec86992b1578';

    public const DIRECT = 'dd2fe0f0-877f-46c8-bcdb-1fce2f2d4c83';

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
