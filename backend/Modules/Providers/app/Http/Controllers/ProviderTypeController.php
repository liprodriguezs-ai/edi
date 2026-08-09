<?php

namespace Modules\Providers\Http\Controllers;

use Modules\Providers\Models\ProviderType;
use QCH\Core\Http\Controllers\Controller;

class ProviderTypeController extends Controller
{
    protected $model = ProviderType::class;

    protected $keyForPermission = 'provider_type';

    public function sortableBy(): array
    {
        return ['name', 'order', 'key'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
