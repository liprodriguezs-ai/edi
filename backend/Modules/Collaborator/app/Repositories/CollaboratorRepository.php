<?php

namespace Modules\Collaborator\Repositories;

use Modules\Collaborator\Models\Collaborator;
use QCH\Core\Repositories\Repository;

class CollaboratorRepository extends Repository
{
    /**
     * Specify Model class name.
     *
     * @return mixed
     */
    public function model()
    {
        return Collaborator::class;
    }
}
