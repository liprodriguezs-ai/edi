<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope for User: when tenancy is initialized, restrict queries to users
 * that belong to the current tenant (via user_tenant pivot). Use User::withoutTenancy()
 * to bypass when needed (e.g. central admin).
 */
class UserTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $this->extend($builder);

        if (! tenancy()->initialized || ! tenant()) {
            return;
        }

        $builder->whereHas('tenants', function (Builder $q): void {
            $q->where(config('tenancy.tenant_model')::make()->getTable().'.id', tenant()->getTenantKey());
        });
    }

    public function extend(Builder $builder): void
    {
        $scope = $this;
        $builder->macro('withoutTenancy', function (Builder $builder) use ($scope): Builder {
            return $builder->withoutGlobalScope($scope);
        });
    }
}
