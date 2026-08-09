<?php

namespace Modules\Access\Http\Controllers;

use Modules\Access\Models\QrType;
use QCH\Core\Http\Controllers\Controller;

class QrTypeController extends Controller
{
    protected $model = QrType::class;

    protected $keyForPermission = 'qr_type';

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
