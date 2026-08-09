<?php

namespace Modules\Providers\Http\Controllers;

use Modules\Providers\Models\ProviderServiceType;
use QCH\Core\Http\Controllers\Controller;

class ProviderServiceTypeController extends Controller
{
    protected $model = ProviderServiceType::class;

    protected $keyForPermission = 'provider_service_type';

    public function sortableBy(): array
    {
        return ['name', 'order', 'key'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
