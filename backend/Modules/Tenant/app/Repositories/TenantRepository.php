<?php

namespace Modules\Tenant\Repositories;

use Modules\Tenant\Models\Tenant;
use QCH\Core\Repositories\Repository;

class TenantRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Tenant::class;
    }
}
