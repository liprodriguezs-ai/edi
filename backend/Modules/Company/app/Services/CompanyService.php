<?php

namespace Modules\Company\Services;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Company\Models\Company;
use Modules\Tenant\Models\Tenant;

class CompanyService
{
    /**
     * Publicly renderable URL for the organization logo, or null when there is none.
     *
     * The media library's url_generator (TenantAwareUrlGenerator) points at
     * `/api/media/{id}/download`, which sits behind auth:sanctum. A browser
     * rendering `<img src="…">` sends no Authorization header, so that URL is
     * unusable from markup and the logo never renders. We sign the public
     * `media.download_signed` route instead — no expiration, so the URL stays
     * stable and browser-cacheable across sessions (branding, not private data).
     */
    public function logoUrl(?Company $company): ?string
    {
        $media = $company?->getFirstMedia('logo');

        if (! $media) {
            return null;
        }

        $params = ['media' => (string) $media->getKey()];

        // The signed route initializes tenancy from this query param, which the
        // media stream needs when the request arrives unauthenticated.
        if ($company->tenant_id) {
            $params['tenant_id'] = (string) $company->tenant_id;
        }

        return URL::signedRoute('media.download_signed', $params);
    }

    /**
     * Organization logo URL resolved from the tenant's main company.
     */
    public function logoUrlForTenant(?Tenant $tenant): ?string
    {
        if (! $tenant) {
            return null;
        }

        $company = Company::query()
            ->where('main', true)
            ->where('tenant_id', $tenant->getTenantKey())
            ->first();

        return $this->logoUrl($company);
    }

    public function handle()
    {
        //
    }

    /**
     * Ensure the tenant has a main company. Creates one if none exists.
     * Call after tenant migrations run (e.g. first login).
     *
     * Company is a central model in a shared DB, so we must scope the lookup to
     * THIS tenant explicitly: pick the main company already owned by this tenant,
     * or a legacy unstamped one (tenant_id NULL) to back-fill. We must NOT pick up
     * another tenant's stamped main company (cross-tenant leak).
     */
    public function ensureMainCompanyForTenant(Tenant $tenant): Company
    {
        // withoutTenancy on purpose: this is the ONE lookup that must see rows the
        // tenant scope hides, because it adopts a main company created before the
        // organization existed (tenant_id still null) and stamps it below. The
        // explicit where() underneath is what keeps it from reaching another
        // tenant's row — it is doing the scoping here, not the global scope.
        $main = Company::query()
            ->withoutTenancy()
            ->where('main', true)
            ->where(function ($q) use ($tenant): void {
                $q->where('tenant_id', $tenant->getTenantKey())
                    ->orWhereNull('tenant_id');
            })
            ->orderByRaw('tenant_id IS NULL') // prefer the already-stamped row
            ->first();

        if ($main) {
            if (! $main->tenant_id) {
                $main->update(['tenant_id' => $tenant->getTenantKey()]);
            }

            return $main;
        }

        // company.slug carries a GLOBAL unique constraint (shared DB), and two
        // tenants can legitimately share a name ("Test Organización"). Suffix
        // the slug with a short, stable fragment of the tenant key so each
        // tenant's auto-created main company has a globally unique slug.
        $tenantKey = (string) $tenant->getTenantKey();
        $slug = Str::slug($tenant->name ?? 'mi-empresa').'-'.substr(str_replace('-', '', $tenantKey), 0, 8);
        $baseName = $tenant->name ?? 'Mi empresa';

        return $this->create([
            'name' => $baseName,
            'slug' => $slug,
            'domain' => '',
            'description' => null,
            'rfc' => null,
            'legal_name' => null,
            'legal_representative' => null,
            'employer_registration' => null,
            'fiscal_address' => null,
            'industry' => null,
            'is_verified' => false,
            'employee_count' => null,
            'main' => true,
            'tenant_id' => $tenant->getTenantKey(),
        ]);
    }

    public function create(array $data)
    {
        $data['slug'] = $data['slug'] ?? Str::slug($data['name'] ?? 'company');
        $company = Company::create($data);
        if (isset($data['favicon'])) {
            $company->clearMediaCollection('favicon');
            $company->addMedia($data['favicon'])->toMediaCollection('favicon');

        }
        if (isset($data['logo'])) {
            $company->clearMediaCollection('logo');
            $company->addMedia($data['logo'])->toMediaCollection('logo');
        }

        return $company->fresh()->load('media');
    }

    public function update(string $id, array $data)
    {
        $company = Company::find($id);
        $company->update($data);
        if (isset($data['favicon'])) {
            if ($company->favicon_url) {
                $company->clearMediaCollection('favicon');
            }
            $company->addMedia($data['favicon'])->toMediaCollection('favicon');
        }

        if (isset($data['logo'])) {
            if ($company->logo_url) {
                $company->clearMediaCollection('logo');
            }
            $company->addMedia($data['logo'])->toMediaCollection('logo');
        }

        return $company;
    }
}
