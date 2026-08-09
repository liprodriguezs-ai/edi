<?php

namespace Modules\Tenant\Http\Controllers;

use App\Services\SubscriptionStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Modules\SubscriptionPlan\Exceptions\CheckoutUnavailableException;
use Modules\SubscriptionPlan\Models\TenantEntitlement;
use Modules\Tenant\Http\Requests\TenantProvisionRequest;
use Modules\Tenant\Services\TenantProvisioningService;

/**
 * Super-admin ALTA of a organization WITH its billing provisioned in one step:
 *   - POST tenant/provision
 *
 * Custom (non-Orion) controller: it owns its constructor and does NOT call
 * parent::__construct() (no $model / CRUD machinery). The super-admin gate uses
 * create.tenant (the ability Orion's tenant store uses), so the same operator who
 * can create a organization can provision it. No initializeTenancy: a super-admin
 * creates ANOTHER organization; every billing write targets the new tenant explicitly.
 */
class TenantProvisioningController extends Controller
{
    public function __construct(
        private readonly TenantProvisioningService $provisioningService,
        private readonly SubscriptionStateService $subscriptionStateService,
    ) {
        $this->middleware(function ($request, $next) {
            $user = auth()->guard('sanctum')->user();

            if (! $user || ! ($user->tokenCan('create.tenant') || $user->tokenCan('*'))) {
                throw new AuthorizationException('Unauthorized action.');
            }

            return $next($request);
        });
    }

    public function store(TenantProvisionRequest $request): JsonResponse
    {
        $actor = auth()->guard('sanctum')->user();

        // Domain guards in the service (invalid mode/method combo, unknown package,
        // missing base, nothing to charge) throw InvalidArgumentException → 422
        // (skill §2.10).
        try {
            $tenant = $this->provisioningService->provision($request->validated(), $actor);
        } catch (CheckoutUnavailableException $e) {
            // Consultative plan on a charging mode → 422 with a machine-readable
            // `code` so the wizard shows the "contáctanos" path.
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason()], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $tenant->load(['payment_type', 'billing_package', 'owners']);

        return response()->json([
            'message' => 'Organización creado y facturación provisionada.',
            'data' => [
                'tenant' => $tenant,
                'subscription' => $this->subscriptionStateService->narrativeFor($actor, $tenant),
                'entitlements_created' => TenantEntitlement::where('tenant_id', $tenant->id)->count(),
            ],
        ], 201);
    }
}
