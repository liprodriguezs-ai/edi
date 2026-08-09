<?php

namespace Modules\ChildRecord\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central sex catalog (D4). Rows are seeded once by
 * CatalogSeeder::seedSexes() and are read-only for tenant roles. The CURP
 * cross-check (REQ-CH-002) and future official exports branch on these
 * stable UUID constants.
 */
#[ObservedBy([GlobalObserver::class])]
class Sex extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'sex';

    public const MALE = 'ed100001-0000-4000-8000-000000000001';

    public const FEMALE = 'ed100001-0000-4000-8000-000000000002';

    protected $fillable = [
        'id',
        'key',
        'name',
        'description',
    ];
}
