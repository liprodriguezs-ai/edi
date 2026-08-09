<?php

namespace Modules\Access\Http\Controllers;

use Modules\Access\Models\AccessVerification;
use QCH\Core\Http\Controllers\Controller;

class AccessVerificationController extends Controller
{
    protected $model = AccessVerification::class;

    protected $keyForPermission = 'access_verification';

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
