<?php

use Illuminate\Support\Facades\Route;
use Modules\AmenityReservations\Http\Controllers\AmenityBlackoutController;
use Modules\AmenityReservations\Http\Controllers\AmenityController;
use Modules\AmenityReservations\Http\Controllers\AmenityReservationController;
use Modules\AmenityReservations\Http\Controllers\AmenitySlotController;
use Modules\AmenityReservations\Http\Controllers\ReservationStatusController;
use Orion\Facades\Orion;

/*
 *--------------------------------------------------------------------------
 * API Routes
 *--------------------------------------------------------------------------
 *
 * Here is where you can register API routes for your application. These
 * routes are loaded by the RouteServiceProvider within a group which
 * is assigned the "api" middleware group. Enjoy building your API!
 *
 * NOTE (S1 — scaffold slice): permissions + menu/module entitlement wiring
 * land in S2/S17. The reservation_status central catalog is not gated by the
 * flag (mirrors charge_status: a read-only catalog, harmless before GA).
 *
 * S4: amenity/amenity_slot/amenity_blackout are gated by BOTH auth/tenancy
 * AND `amenityReservationsEnabled` (the per-tenant kill-switch registered by
 * AmenityReservationsServiceProvider — see EnsureAmenityReservationsEnabled).
 * This is independent from `moduleEnabled` (billing entitlement) used by
 * other modules: this flag defaults to false (S1) and only flips on when a
 * tenant is explicitly enrolled, ahead of the GA slice (S17).
 *
 * S5: GET amenity/{id}/availability (structural + occupancy availability).
 * Registered — together with the S4 media actions — BEFORE Orion::resource
 * ('amenity') so these literal-segment custom routes are matched first,
 * mirroring Housing's `unit/my` (registered before `unit/{id}`).
 *
 * S6: POST amenity_reservation (creation). ReservationService::create() owns
 * every business rule; the Orion resource here only wires permissions/
 * tenancy/flag gating + the CreateRequest.
 *
 * S7: POST amenity_reservation/{id}/{approve|reject|cancel|complete|no-show}
 * (FSM transitions). Registered under the same custom-action middleware as
 * amenity's availability/media routes (rejects refresh tokens + requires an
 * active account) — BEFORE Orion::resource('amenity_reservation') so these
 * literal-segment routes are matched first, same convention as amenity/{id}
 * /availability above.
 *
 * S9: POST amenity_reservation/{id}/surcharges (admin/board-only incident
 * charges). Same custom-action middleware group, registered alongside the
 * FSM transitions above.
 *
 * S18: GET amenity_reservation/occupancy (anonymized cluster-wide occupancy
 * calendar for the oversight committee — read.amenity_reservation gated, NO
 * unit/user/reservation-id in the response). Registered BEFORE
 * Orion::resource('amenity_reservation') so the literal "occupancy" segment
 * is never captured as the show route's {id}.
 */
Route::middleware('throttle:api')->group(function (): void {
    $tenancyMiddleware = ['auth:sanctum', 'initializeTenancy'];

    Orion::resource('reservation_status', ReservationStatusController::class)
        ->withSoftDeletes()
        ->middleware($tenancyMiddleware);

    $amenityTenancyMiddleware = ['auth:sanctum', 'initializeTenancy', 'amenityReservationsEnabled'];
    $amenityCustomMiddleware = ['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy', 'amenityReservationsEnabled'];

    Route::middleware($amenityCustomMiddleware)->group(function (): void {
        Route::get('amenity/{id}/availability', [AmenityController::class, 'availability']);
        Route::post('amenity/{id}/media', [AmenityController::class, 'uploadMedia']);
        Route::delete('amenity/{id}/media/{mediaId}', [AmenityController::class, 'deleteMedia']);

        // S18 (oversight occupancy calendar): literal-segment route, registered
        // BEFORE Orion::resource('amenity_reservation') so "occupancy" is never
        // matched as the show route's {id}, same convention as amenity/{id}
        // /availability above.
        Route::get('amenity_reservation/occupancy', [AmenityReservationController::class, 'occupancy']);

        Route::post('amenity_reservation/{id}/approve', [AmenityReservationController::class, 'approve']);
        Route::post('amenity_reservation/{id}/reject', [AmenityReservationController::class, 'reject']);
        Route::post('amenity_reservation/{id}/cancel', [AmenityReservationController::class, 'cancel']);
        Route::post('amenity_reservation/{id}/complete', [AmenityReservationController::class, 'complete']);
        Route::post('amenity_reservation/{id}/no-show', [AmenityReservationController::class, 'noShow']);
        Route::post('amenity_reservation/{id}/surcharges', [AmenityReservationController::class, 'surcharges']);
    });

    Orion::resource('amenity', AmenityController::class)
        ->withSoftDeletes()
        ->middleware($amenityTenancyMiddleware);

    Orion::resource('amenity_slot', AmenitySlotController::class)
        ->withSoftDeletes()
        ->middleware($amenityTenancyMiddleware);

    Orion::resource('amenity_blackout', AmenityBlackoutController::class)
        ->withSoftDeletes()
        ->middleware($amenityTenancyMiddleware);

    Orion::resource('amenity_reservation', AmenityReservationController::class)
        ->withSoftDeletes()
        ->middleware($amenityTenancyMiddleware);
});
