<?php

namespace Modules\Email\Repositories;

use Modules\Email\Models\Email;
use QCH\Core\Repositories\Repository;

class EmailRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Email::class;
    }
}
