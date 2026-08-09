<?php

namespace Modules\Module\Repositories;

use Modules\Module\Models\Module;
use QCH\Core\Repositories\Repository;

class ModuleRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Module::class;
    }
}
