<?php

namespace Modules\Company\Repositories;

use Modules\Company\Models\Frequency;
use QCH\Core\Repositories\Repository;

class FrequencyRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Frequency::class;
    }
}
