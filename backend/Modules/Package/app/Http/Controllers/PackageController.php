<?php

namespace Modules\Package\Http\Controllers;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collections\Services\DelinquencyRestrictionService;
use Modules\Housing\Models\UnitUser;
use Modules\Package\Http\Requests\PackageBatchRequest;
use Modules\Package\Http\Requests\PackageIncidentRequest;
use Modules\Package\Http\Requests\PackagePickupRequest;
use Modules\Package\Http\Requests\PackageReassignRequest;
use Modules\Package\Http\Requests\PackageReturnRequest;
use Modules\Package\Models\Package;
use Modules\Package\Models\PackageStatus;
use Modules\Package\Services\PackageService;
use Orion\Http\Requests\Request as OrionRequest;
use QCH\Core\Http\Controllers\Controller;

class PackageController extends Controller
{
    protected $model = Package::class;

    protected $keyForPermission = 'package';

    /**
     * Active unit ids the caller belongs to, memoized for the request so the index/show
     * scope and the pickup-code reveal share a single unit_user query.
     *
     * @var array<int, string>|null
     */
    private ?array $callerActiveUnitIds = null;

    public function includes(): array
    {
        // received_by_user and picked_up_by_user use withoutGlobalScope(UserTenantScope)
        // so they resolve guards (whose users are not tenant-scoped) correctly.
        // The ids are always set by the authenticated guard of THIS tenant, so no
        // cross-tenant user can leak through these relations.
        return ['unit', 'status', 'carrier', 'type', 'recipient_user', 'received_by_user', 'picked_up_by_user', 'media'];
    }

    public function filterableBy(): array
    {
        return ['unit_id', 'package_status_id', 'package_carrier_id', 'package_type_id', 'received_at'];
    }

    public function sortableBy(): array
    {
        return ['received_at', 'picked_up_at'];
    }

    public function searchableBy(): array
    {
        return ['tracking_number', 'carrier_other', 'description', 'storage_location', 'recipient_name'];
    }

    /**
     * Residents/renters see only packages for units they actively belong to.
     * Guards/admins (create.package) and board read-only roles (read.report_package)
     * see all tenant packages, aligning the list scope with the aging report.
     */
    protected function buildIndexFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildIndexFetchQuery($request, $requestedRelations);

        return $this->scopeToResidentUnitsIfNeeded($query);
    }

    protected function buildShowFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildShowFetchQuery($request, $requestedRelations);

        return $this->scopeToResidentUnitsIfNeeded($query);
    }

    /**
     * Reveal pickup_code to callers who are active members of each package's unit.
     */
    protected function afterIndex(Request $request, $entities): null
    {
        $this->revealPickupCodeForUnitMembers($entities);

        return null;
    }

    protected function afterShow(Request $request, Model $entity): null
    {
        $this->revealPickupCodeForUnitMembers([$entity]);

        return null;
    }

    protected function afterSearch($query, &$entities): void
    {
        parent::afterSearch($query, $entities);
        $this->revealPickupCodeForUnitMembers($entities);
    }

    /**
     * Delegate intake to PackageService::register.
     * Orion calls performStore then re-fetches the entity, so we swap $entity's
     * attributes with the persisted model returned by the service.
     */
    protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
    {
        $created = app(PackageService::class)->register(
            $request->validated(),
            $request->user()
        );

        $entity->setRawAttributes($created->getAttributes());
        $entity->syncOriginal();
        $entity->exists = true;
    }

    /**
     * Return the two pickup-policy booleans relevant to handover.
     *
     * Requires update.package — guards, admins, and presidents hold this ability.
     * Residents (read.package only) get 403. This avoids the 403 that occurs when
     * guards call GET /config/package-settings (which requires read.configuration).
     */
    public function pickupPolicy(Request $request): JsonResponse
    {
        $this->authorize('update.package', Package::class);

        return response()->json(app(PackageService::class)->getPickupPolicy());
    }

    /**
     * GET package/restricted-units — unit ids currently blocked from package
     * intake (design §6.3, R-DELQ-7). Ids ONLY — no amounts, statuses, or
     * names. Gated by create.package (guards/admins); a resident (no
     * create.package) gets 403. Deliberately NOT exposed via /selects, which
     * has no permission gate.
     */
    public function restrictedUnits(Request $request): JsonResponse
    {
        $this->authorize('create.package', Package::class);

        $unitIds = app(DelinquencyRestrictionService::class)->restrictedUnitIds(
            DelinquencyRestrictionService::FEATURE_PACKAGE_INTAKE,
        );

        return response()->json(['unit_ids' => $unitIds]);
    }

    /**
     * Batch registration: one transaction for up to 50 packages.
     */
    public function batchRegister(PackageBatchRequest $request): JsonResponse
    {
        $this->authorize('create.package', Package::class);

        $created = app(PackageService::class)->batchRegister(
            $request->validated('packages'),
            $request->user()
        );

        return response()->json(['data' => $created], 201);
    }

    // ── WU-2 — Pickup & Custody ──────────────────────────────────────────────

    /**
     * Scan a resident's QR pickup code and return all READY packages for the unit.
     *
     * Authorization: requires update.package (guards/admins only).
     * Security: pickup_code is NEVER included in the response.
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $this->authorize('update.package', Package::class);

        $request->validate([
            'pickup_code' => ['required', 'string'],
        ]);

        $result = app(PackageService::class)->verifyPickupCode($request->input('pickup_code'));

        return response()->json($result);
    }

    /**
     * Hand over one or more packages to a resident or their proxy.
     *
     * Authorization: requires update.package. The entire operation is atomic:
     * if any package is not READY, all changes are rolled back (service handles this).
     */
    public function handover(PackagePickupRequest $request): JsonResponse
    {
        $this->authorize('update.package', Package::class);

        $packages = app(PackageService::class)->handover(
            $request->input('package_ids'),
            $request->validated(),
            $request->user()
        );

        return response()->json(['data' => $packages]);
    }

    /**
     * Return packages for the authenticated resident's active units.
     *
     * Without ?history=1 (default): non-terminal packages (pending pickup) — same as before.
     * With ?history=1: terminal packages (picked_up/returned/incident), most-recent first,
     * capped at the last 60 days. History items receive richer includes so the resident
     * can see who handed over the package and what evidence was captured.
     *
     * pickup_code is only revealed on READY (non-terminal) packages — terminal packages
     * cannot be retrieved anyway so exposing the code is meaningless.
     *
     * Authorization: requires read.package. Staff exclusion: users whose token
     * carries create.package are guards or admins — they must use GET /api/package.
     */
    public function myPackages(Request $request): JsonResponse
    {
        $this->authorize('read.package', Package::class);

        // Staff exclusion: guards/admins hold create.package on their token.
        // They see all tenant packages via the main index; my-packages is for residents.
        if ($request->user()?->tokenCan('create.package')) {
            abort(403, 'Guardias y administradores deben usar GET /api/package');
        }

        $unitIds = UnitUser::where('user_id', $request->user()->id)
            ->whereNull('end_date')
            ->pluck('unit_id')
            ->all();

        $terminalStatuses = [PackageStatus::PICKED_UP, PackageStatus::RETURNED, PackageStatus::INCIDENT];

        if ($request->boolean('history')) {
            // History: terminal packages from the last 60 days, newest first.
            $since = now()->subDays(60);

            $packages = Package::whereIn('unit_id', $unitIds)
                ->whereIn('package_status_id', $terminalStatuses)
                ->where('updated_at', '>=', $since)
                ->with(['unit', 'status', 'carrier', 'type', 'media', 'picked_up_by_user'])
                ->orderByDesc('updated_at')
                ->get();

            // pickup_code is NOT revealed for terminal packages — no operational value.

            return response()->json(['data' => $packages]);
        }

        $packages = Package::whereIn('unit_id', $unitIds)
            ->whereNotIn('package_status_id', $terminalStatuses)
            ->with(['unit', 'status', 'carrier', 'type', 'media'])
            ->get();

        // Expose pickup_code only on non-terminal packages owned by this resident.
        $packages->each(fn (Package $pkg) => $pkg->makeVisible('pickup_code'));

        return response()->json(['data' => $packages]);
    }

    /**
     * Return a package to sender (changes status to RETURNED).
     *
     * The package is resolved by id INSIDE the method (not via implicit route-model
     * binding) so the lookup runs after InitializeTenancyFromUser — keeping it
     * tenant-scoped. A foreign-tenant id therefore 404s instead of mutating.
     */
    public function returnPackage(PackageReturnRequest $request, string $packageId): JsonResponse
    {
        $this->authorize('update.package', Package::class);

        $package = Package::findOrFail($packageId);

        $updated = app(PackageService::class)->returnPackage($package, $request->validated('reason'));

        return response()->json(['data' => $updated]);
    }

    /**
     * Flag a package as having an incident (damaged, lost, disputed).
     *
     * Package resolved tenant-scoped inside the method (see returnPackage rationale).
     */
    public function flagIncident(PackageIncidentRequest $request, string $packageId): JsonResponse
    {
        $this->authorize('update.package', Package::class);

        $package = Package::findOrFail($packageId);

        $updated = app(PackageService::class)->flagIncident($package, $request->validated('reason'));

        return response()->json(['data' => $updated]);
    }

    /**
     * Reassign a misdelivered package to the correct unit in the same tenant.
     *
     * Package resolved tenant-scoped inside the method (see returnPackage rationale).
     */
    public function reassignUnit(PackageReassignRequest $request, string $packageId): JsonResponse
    {
        $this->authorize('update.package', Package::class);

        $package = Package::findOrFail($packageId);

        $updated = app(PackageService::class)->reassignUnit($package, $request->validated('unit_id'));

        return response()->json(['data' => $updated]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Restrict the query to packages whose unit_id is in the caller's active units,
     * unless the caller's Sanctum token carries the create.package ability (guards / admins)
     * or the read.report_package ability (board read-only roles such as Treasurer and
     * Oversight Committee, who need cluster-wide list visibility to match the report).
     * Callers with neither ability (residents/renters) are scoped to their own units.
     * pickup_code visibility is still scoped separately (only to active unit members).
     */
    private function scopeToResidentUnitsIfNeeded(Builder $query): Builder
    {
        $user = auth()->guard('sanctum')->user();

        if (! $user) {
            return $query;
        }

        // Guards/admins (create.package), board read-only roles (read.report_package),
        // and super-admin (*) all see all tenant packages in the list.
        if ($user->tokenCan('create.package') || $user->tokenCan('read.report_package') || $user->tokenCan('*')) {
            return $query;
        }

        return $query->whereIn('unit_id', $this->resolveCallerActiveUnitIds($user->id));
    }

    /**
     * Resolve (and memoize) the active unit ids the given caller belongs to
     * (unit_user without end_date). One query per request, shared by the index/show
     * scope and the pickup-code reveal.
     *
     * @return array<int, string>
     */
    private function resolveCallerActiveUnitIds(string $userId): array
    {
        return $this->callerActiveUnitIds ??= UnitUser::where('user_id', $userId)
            ->whereNull('end_date')
            ->pluck('unit_id')
            ->all();
    }

    /**
     * Reveal pickup_code only on packages whose unit the authenticated caller
     * actively belongs to (unit_user without end_date).
     *
     * @param  Paginator|Collection|array<int, Package>|Package  $entities
     */
    private function revealPickupCodeForUnitMembers(mixed $entities): void
    {
        $userId = auth()->guard('sanctum')->id();

        if (! $userId) {
            return;
        }

        $rows = $entities instanceof Paginator ? $entities->items() : (array) $entities;

        if (empty($rows)) {
            return;
        }

        // Reuse the request-memoized active unit ids (shared with the index/show scope)
        // to avoid a second unit_user query.
        $memberUnitIds = array_flip($this->resolveCallerActiveUnitIds($userId));

        foreach ($rows as $package) {
            if (isset($memberUnitIds[$package->unit_id])) {
                $package->makeVisible('pickup_code');
            }
        }
    }
}
