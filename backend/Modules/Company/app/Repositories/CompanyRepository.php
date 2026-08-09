<?php

namespace Modules\Company\Repositories;

use Modules\Company\Models\Company;
use QCH\Core\Repositories\Repository;

class CompanyRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Company::class;
    }
}
