<?php

namespace Tests\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolución de filas de catálogos tenant-scoped en tests.
 *
 * Los 12 catálogos autoadministrables ya no tienen UUIDs fijos (cada tenant
 * posee sus propias filas), así que los tests los resuelven por nombre. La
 * fila se acota así, en orden:
 *
 *  1. $tenant explícito (tests multi-tenant: aislamiento, duplicados…)
 *  2. tenancy inicializada (dentro de $tenant->run(...) el TenantScope aplica)
 *  3. la fila MÁS RECIENTE con ese nombre — en clases sin transacciones
 *     ($connectionsToTransact = []) el estado se acumula entre tests y el
 *     tenant del test en curso es siempre el último sembrado; los ids son
 *     UUID v7 (ordenados por tiempo), así que orderByDesc('id') es estable.
 */
final class Catalog
{
    public static function id(string $model, string $name, Model|string|null $tenant = null): string
    {
        /** @var Builder $query */
        $query = $model::query();

        if ($tenant !== null) {
            $tenantKey = $tenant instanceof Model ? $tenant->getKey() : $tenant;
            $query->withoutGlobalScopes()->whereNull('deleted_at')->where('tenant_id', $tenantKey);
        } elseif (! tenancy()->initialized) {
            $query->withoutGlobalScopes()->whereNull('deleted_at')->orderByDesc('id');
        }

        return $query->where('name', $name)->firstOrFail()->id;
    }
}
