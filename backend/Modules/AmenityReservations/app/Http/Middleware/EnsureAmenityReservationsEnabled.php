<?php

namespace Modules\AmenityReservations\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\AmenityReservations\Support\AmenityReservationsSettings;
use Modules\Setting\Models\Setting;
use QCH\Core\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-tenant kill-switch for the WHOLE AmenityReservations module
 * (`amenity_reservations.enabled`, defaulted to `false` by
 * AmenityReservationsDatabaseSeeder in S1).
 *
 * This is a DIFFERENT mechanism from `moduleEnabled` (billing entitlement via
 * `module_tenant`, see `QCH\Core\Http\Middleware\VerifyModuleEnabled`): this
 * flag exists purely to keep the module dormant while it is built across
 * S1..S17 and is NOT wired to billing/entitlements. It gates every amenity
 * domain route (amenity, amenity_slot, amenity_blackout, and — in later
 * slices — amenity_reservation) independently of the module's own
 * permissions (S2) and of menu visibility (S17).
 *
 * Must run AFTER `initializeTenancy` so `Setting::getValue()` resolves against
 * the correct tenant (`Setting` is tenant-scoped via `BelongsToTenant`).
 *
 * Registered as the `amenityReservationsEnabled` alias by
 * `AmenityReservationsServiceProvider::registerMiddlewareAliases()` — mirrors
 * the `User` module's `termsAccepted` self-registration pattern so the gate
 * ships with the module that owns it, without touching bootstrap/app.php.
 */
class EnsureAmenityReservationsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Super admin / lawyer operate cross-tenant and may exercise
        // pre-GA endpoints for verification, mirroring VerifyModuleEnabled.
        if ($user && $user->hasRole([Role::SLUG_SUPER_ADMIN, Role::SLUG_LAWYER])) {
            return $next($request);
        }

        // No tenant initialized: nothing to gate against, let it through
        // (defensive — every route in this group already runs initializeTenancy).
        if (! tenancy()->initialized) {
            return $next($request);
        }

        if (Setting::getValue(AmenityReservationsSettings::ENABLED) === true) {
            return $next($request);
        }

        return response()->json([
            'message' => 'El módulo de amenidades no está habilitado para este condominio.',
            'code' => 'AMENITY_RESERVATIONS_DISABLED',
        ], 403);
    }
}
