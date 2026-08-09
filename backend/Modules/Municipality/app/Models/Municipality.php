<?php

namespace Modules\Municipality\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Country\Models\Country;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// use Modules\Municipality\Database\Factories\MunicipalityFactory;
#[ObservedBy([GlobalObserver::class])]
class Municipality extends BaseModel
{
    use CentralConnection;
    use HasFactory;

    public function getKeysCountry()// agg
    {
        return ['country_id'];
    }

    protected $table = 'municipality';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'code',
        'state_code',
        'country_id',
        'state_id',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
    // protected static function newFactory(): MunicipalityFactory
    // {
    //     // return MunicipalityFactory::new();
    // }
}
