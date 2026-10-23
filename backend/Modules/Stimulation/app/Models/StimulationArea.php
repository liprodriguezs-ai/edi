<?php

namespace Modules\Stimulation\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;

/**
 * Central catalog of stimulation areas worked in the core activity of a
 * session (D4). Rows are seeded once by CatalogSeeder::seedStimulationAreas()
 * and are read-only for tenant roles.
 */
#[ObservedBy([GlobalObserver::class])]
class StimulationArea extends CentralCatalogModel
{
    protected $table = 'stimulation_area';

    public const SENSORY = 'ed400002-0000-4000-8000-000000000001';

    public const COGNITIVE = 'ed400002-0000-4000-8000-000000000002';

    public const SOCIO_AFFECTIVE = 'ed400002-0000-4000-8000-000000000003';

    public const LANGUAGE = 'ed400002-0000-4000-8000-000000000004';

    public const MOTOR = 'ed400002-0000-4000-8000-000000000005';

    protected $fillable = [
        'id',
        'key',
        'name',
        'description',
    ];
}
