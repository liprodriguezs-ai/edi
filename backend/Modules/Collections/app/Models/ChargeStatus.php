<?php

namespace Modules\Collections\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

#[ObservedBy([GlobalObserver::class])]
class ChargeStatus extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'charge_status';

    // Status key constants — CENTRAL/shared catalog (no tenant_id; fixed UUIDs
    // seeded once by CatalogSeeder and referenced by code across all tenants).
    public const COMPLETED = 'd533b5e0-9b64-4910-812f-a3d49517acc1';

    public const PARTIAL = 'bdd1e2ad-afa8-4ddf-bbc1-bc870c41f538';

    public const OVERDUE = '6413740b-696f-4025-910b-0a84a3853c85';

    public const DUE_SOON = 'a9ebca16-6a08-4316-9b36-980002c49ca2';

    public const OVERPAID = '88ebed96-a48e-42f3-baf0-e40f57e31aed';

    public const PENDING = '42ce6f04-3298-46d3-be94-071f38abc3c5';

    public const PAYMENT_PLAN = '40247d14-a4c5-49d0-a25e-48cdb05a5c64'; // v1.2 — seeded but no flow yet

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
