<?php

namespace Modules\AmenityReservations\Support;

/**
 * Central reference for the AmenityReservations per-tenant kill-switch.
 *
 * `amenity_reservations.enabled` is a Setting slug (tenant-scoped key-value)
 * that gates the whole module for a tenant, independent from menu visibility
 * (S17) and from the module's own permission set (S2). Defaults to `false` —
 * the module stays dormant until the GA slice explicitly flips it on for a
 * tenant.
 *
 * Keeping the slug and default here (mirroring Collections\Support\CollectionSettings)
 * avoids drift between the seeder, the future config controller, and any
 * service/middleware that checks the flag.
 */
final class AmenityReservationsSettings
{
    public const ENABLED = 'amenity_reservations.enabled';

    public const DEFAULT_ENABLED = false;
}
