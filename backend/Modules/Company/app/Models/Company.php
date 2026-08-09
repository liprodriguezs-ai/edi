<?php

namespace Modules\Company\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\BaseModel;
use QCH\Core\Models\GlobalObserver;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

// use Modules\Company\Database\Factories\CompanyFactory;

/**
 * GlobalObserver stamps the user_created_id / user_updated_id audit columns
 * (§16.4 r5 — it was commented out, so general/branding edits left no actor).
 * It no-ops when there is no authenticated user, so onboarding/system writes
 * (e.g. CompanyService::ensureMainCompanyForTenant before login) are unaffected.
 */
#[ObservedBy([GlobalObserver::class])]
class Company extends BaseModel implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;

    protected $table = 'company';

    protected $fillable = [
        'slug',
        'domain',
        'name',
        'description',
        'rfc',
        'legal_name',
        'legal_representative',
        'employer_registration',
        'fiscal_address',
        'constitutive_deed_number',
        'constitutive_deed_date',
        'constitutive_notary_number',
        'constitutive_notary_name',
        'constitutive_notary_state',
        'industry',
        'is_verified',
        'employee_count',
        'main',
        'tenant_id',
        'phone',
        'email',
        'website',
        'short_name',
        'tagline',
    ];

    protected function casts(): array
    {
        return [
            'main' => 'boolean',
        ];
    }

    /**
     * Single-file media collection for the organization's visual identity (§16.3.2).
     *
     * Uploading a new logo replaces the previous file automatically
     * (singleFile()), so the collection always holds at most one current asset.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('logo')->singleFile();
    }

    /**
     * The current tenant's main company.
     *
     * Company is a CENTRAL model (no BelongsToTenant — it is also a super-admin
     * Orion resource, and is created/read outside tenant context during
     * onboarding, so we intentionally do NOT auto-stamp/scope every query). But a
     * bare where('main', true) in the shared DB returns the FIRST main company
     * across ALL tenants — a cross-tenant leak of organization identity. When tenancy
     * is initialized we therefore scope main() by the current tenant_id; outside
     * tenant context (super-admin / onboarding) it stays unscoped.
     */
    public function scopeMain($query)
    {
        $query->where('main', true);

        if (tenancy()->initialized) {
            $query->where('tenant_id', tenant()->getTenantKey());
        }

        return $query;
    }
}
