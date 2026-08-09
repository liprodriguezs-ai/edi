<?php

namespace Modules\Access\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class AccessVerification extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'access_verification';

    public const QR = '0c0f8b97-c90c-49c7-ace3-37f30f3d55da';

    public const APP_REMOTE = '2124a12c-ef58-457f-8a3c-33ee5b2e0d21';

    public const PHONE_CALL = '0fd165c2-5438-43f4-8b88-ce254cdfff6b';

    protected $fillable = [
        'id',
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
