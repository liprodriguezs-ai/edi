<?php

namespace Modules\Country\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

// agg
// use Modules\Country\Database\Factories\CountryFactory;
#[ObservedBy([GlobalObserver::class])]
class Country extends BaseModel
{
    use CentralConnection;
    use HasFactory;

    const MEXICO = 'bb30ad4e-4dfd-48c0-ab09-eb0dbf522cd8';

    protected $table = 'country';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'id',
        'name',
        'code',
        'phone_code',
    ];

    // protected static function newFactory(): CountryFactory
    // {
    //     // return CountryFactory::new();
    // }
}
