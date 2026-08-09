<?php

namespace Modules\Setting\Http\Controllers;

use Modules\Setting\Http\Requests\SettingCreateRequest;
use Modules\Setting\Http\Requests\SettingUpdateRequest;
use Modules\Setting\Models\Setting;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class SettingController extends Controller
{
    protected $model = Setting::class;

    protected $keyForPermission = 'setting';

    public function searchableBy(): array
    {
        return ['name', 'slug', 'description'];
    }

    public function filterableBy(): array
    {
        return ['type'];
    }

    public function sortableBy(): array
    {
        return ['name', 'slug', 'type', 'created_at', 'updated_at'];
    }

    protected function storeRequest(Request $request): string
    {
        return SettingCreateRequest::class;
    }

    protected function updateRequest(Request $request): string
    {
        return SettingUpdateRequest::class;
    }
}
