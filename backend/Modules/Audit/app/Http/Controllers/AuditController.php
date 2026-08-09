<?php

namespace Modules\Audit\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Modules\Audit\Http\Resources\AuditResource;
use Modules\Audit\Models\Audit;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class AuditController extends Controller
{
    protected $model = Audit::class;

    protected $keyForPermission = 'audit';

    protected $resource = AuditResource::class;

    /**
     * §18.2 / findings global-01, reports-02 — the audits table is central
     * (no TenantScope), so without this filter any account owner could read
     * every cluster's trail. Tenant members only see their own tenant's rows;
     * the platform super admin (wildcard token) may read across tenants.
     */
    protected function buildFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildFetchQuery($request, $requestedRelations);

        if (tenancy()->initialized) {
            return $query->where($query->qualifyColumn('tenant_id'), tenant('id'));
        }

        $user = auth()->guard('sanctum')->user();
        if ($user && $user->tokenCan('*')) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }

    public function includes(): array
    {
        return [
            'user',
        ];
    }

    public function filterableBy(): array
    {
        return [
            'event',
            'user.name',
            'user.last_name',
            'user.second_last_name',
            'user.email',
            'user.phone',
        ];
    }
}
