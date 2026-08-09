<?php

namespace Modules\Role\Repositories;

use Modules\Role\Models\Role;
use QCH\Core\Repositories\Repository;

class RoleRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Role::class;
    }
}
