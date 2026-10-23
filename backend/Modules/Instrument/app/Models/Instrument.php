<?php

namespace Modules\Instrument\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\HasMany;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Registry anchor (D1): a screening instrument whose content is versioned.
 * EDI is the first (and, for now, only) row. Handcrafted UUID constant --
 * not bulk content.
 */
#[ObservedBy([GlobalObserver::class])]
class Instrument extends CentralCatalogModel
{
    protected $table = 'instrument';

    public const EDI = 'ed200004-0000-4000-8000-000000000001';

    protected $fillable = [
        'id',
        'key',
        'name',
        'description',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(InstrumentVersion::class, 'instrument_id');
    }
}
