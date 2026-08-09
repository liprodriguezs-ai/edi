<?php

namespace Modules\Setting\Repositories;

use Modules\Setting\Models\Setting;
use QCH\Core\Repositories\Repository;

class SettingRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Setting::class;
    }
}
