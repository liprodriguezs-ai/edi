<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central application-position catalog (D2/D3), NOT versioned. Rows are
 * seeded once by CatalogSeeder::seedApplicationPositions() and are read-only
 * for tenant roles.
 */
#[ObservedBy([GlobalObserver::class])]
class ApplicationPosition extends CentralCatalogModel
{
    protected $table = 'application_position';

    public const SUPINE = 'ed200003-0000-4000-8000-000000000001';

    public const PRONE = 'ed200003-0000-4000-8000-000000000002';

    public const SITTING = 'ed200003-0000-4000-8000-000000000003';

    public const ON_FLOOR = 'ed200003-0000-4000-8000-000000000004';

    public const STANDING = 'ed200003-0000-4000-8000-000000000005';

    public const NOT_APPLICABLE = 'ed200003-0000-4000-8000-000000000006';

    protected $fillable = [
        'id',
        'key',
        'name',
        'legacy_id',
    ];

    protected $hidden = [
        'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'legacy_id' => 'integer',
        ];
    }
}
