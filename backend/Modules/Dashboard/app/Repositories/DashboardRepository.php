<?php

namespace Modules\Dashboard\Repositories;

use Modules\Dashboard\Models\Dashboard;
use QCH\Core\Repositories\Repository;

class DashboardRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Dashboard::class;
    }
}
