<?php

namespace Modules\Tenant\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Modules\Tenant\Http\Requests\TenantUpdateRequest;
use Modules\Tenant\Models\Tenant;
use Modules\Tenant\Services\TenantService;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TenantController extends Controller
{
    protected $model = Tenant::class;

    protected $keyForPermission = 'tenant';

    public function alwaysIncludes(): array
    {
        return ['payment_type', 'billing_package', 'owners'];
    }

    public function sortableBy(): array
    {
        return ['name', 'status', 'created_at', 'updated_at'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function filterableBy(): array
    {
        return ['name', 'status', 'tenant_payment_type_id', 'billing_package_id'];
    }

    /**
     * Delegates update to TenantService; catches 409 for active subscription conflicts.
     */
    public function update(Request $request, ...$args)
    {
        try {
            return parent::update($request, ...$args);
        } catch (ConflictHttpException $e) {
            $this->rollbackTransaction();

            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'ACTIVE_SUBSCRIPTION',
            ], 409);
        }
    }

    /**
     * Delegates update to TenantService while keeping Orion's update flow.
     */
    protected function performUpdate(Request $request, Model $entity, array $attributes): void
    {
        app(TenantUpdateRequest::class)->validated();

        app(TenantService::class)->update($request->all(), $entity->getKey());
    }
}
