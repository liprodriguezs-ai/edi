<?php

namespace Modules\Role\Services;

use Modules\Role\Models\Role;

class RoleService
{
    public function create(array $data)
    {
        $data['guard_name'] = 'api';
        $role = Role::create($data);

        return $role;
    }
}
