<?php

namespace Modules\UserProfile\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Modules\UserProfile\Models\UserProfile;
use Orion\Http\Requests\Request;
use QCH\Core\Http\Controllers\Controller;

class UserProfileController extends Controller
{
    protected $model = UserProfile::class;

    protected $keyForPermission = 'user_profile';

    /**
     * §18.3 (global-03) — roles that may see every profile in the tenant.
     * Everyone else (resident, renter, security, operations_staff, …) is
     * strictly self-service: index lists only their own profile and any other
     * profile resolves to 404 on show/update/destroy.
     */
    private const TENANT_WIDE_ROLES = [
        'admin.super',
        'account.owner',
        'company.admin',
        'administrator',
        'president',
        'treasurer',
    ];

    public function includes(): array
    {
        return ['user'];
    }

    public function filterableBy(): array
    {
        return ['user_id'];
    }

    /**
     * RFC/CURP lookups are an administrative tool — exposing them to residents
     * lets neighbours mine each other's PII (global-03). Relation columns
     * ('user.name') are excluded: Orion builds lower(user.name) unquoted and
     * `user` is a reserved word in Postgres (syntax error 42601).
     */
    public function searchableBy(): array
    {
        if ($this->isTenantWideActor()) {
            return ['rfc', 'curp'];
        }

        return [];
    }

    public function sortableBy(): array
    {
        return ['user.name', 'birthdate', 'created_at'];
    }

    protected function buildFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildFetchQuery($request, $requestedRelations);

        if ($this->isTenantWideActor()) {
            return $query;
        }

        $user = auth()->guard('sanctum')->user();

        return $query->where(
            $query->qualifyColumn('user_id'),
            $user instanceof User ? $user->id : null
        );
    }

    private function isTenantWideActor(): bool
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->tokenCan('*')) {
            return true;
        }

        return $user->roles->pluck('name')
            ->intersect(self::TENANT_WIDE_ROLES)
            ->isNotEmpty();
    }
}
