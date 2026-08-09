<?php

namespace Modules\Municipality\Repositories;

use Modules\Municipality\Models\Municipality;
use QCH\Core\Repositories\Repository;

class MunicipalityRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Municipality::class;
    }
}
