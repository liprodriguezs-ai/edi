<?php

namespace Modules\User\Repositories;

use Modules\User\Models\User;
use QCH\Core\Repositories\Repository;

class UserRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return User::class;
    }
}
