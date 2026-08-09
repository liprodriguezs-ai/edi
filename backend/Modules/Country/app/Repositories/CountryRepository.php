<?php

namespace Modules\Country\Repositories;

use Modules\Country\Models\Country;
use QCH\Core\Repositories\Repository;

class CountryRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Country::class;
    }
}
