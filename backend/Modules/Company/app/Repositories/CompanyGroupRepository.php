<?php

namespace Modules\Company\Repositories;

use Modules\Company\Models\CompanyGroup;
use QCH\Core\Repositories\Repository;

class CompanyGroupRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return CompanyGroup::class;
    }
}
