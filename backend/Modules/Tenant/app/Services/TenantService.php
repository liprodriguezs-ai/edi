<?php

namespace Modules\Tenant\Services;

use Modules\Tenant\Models\Tenant;

class TenantService
{
    public function update(array $data, string $tenantId): Tenant
    {
        $tenant = Tenant::findOrFail($tenantId);

        // `force` was a legacy flag consumed by the removed Stripe-plan-change
        // guard; drop it so it never leaks into the tenant's attributes.
        unset($data['force']);

        $tenant->update($data);

        return $tenant;
    }
}
