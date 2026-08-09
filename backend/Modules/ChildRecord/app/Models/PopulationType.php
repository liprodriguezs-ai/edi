<?php

namespace Modules\ChildRecord\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central population-type catalog (D4). Rows are seeded once by
 * CatalogSeeder::seedPopulationTypes(); the Secretaría de Salud export
 * branches on these stable UUID constants.
 */
#[ObservedBy([GlobalObserver::class])]
class PopulationType extends CentralCatalogModel
{
    use HasFactory;

    protected $table = 'population_type';

    public const URBAN = 'ed100002-0000-4000-8000-000000000001';

    public const RURAL = 'ed100002-0000-4000-8000-000000000002';

    public const INDIGENOUS = 'ed100002-0000-4000-8000-000000000003';

    protected $fillable = [
        'id',
        'key',
        'name',
        'description',
    ];
}
