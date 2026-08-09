<?php

namespace Modules\Board\Http\Controllers;

use Modules\Board\Models\BoardRole;
use QCH\Core\Http\Controllers\Controller;

class BoardRoleController extends Controller
{
    protected $model = BoardRole::class;

    protected $keyForPermission = 'board_role';

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active', 'created_at'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
