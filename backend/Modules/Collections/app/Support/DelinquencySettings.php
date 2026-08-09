<?php

namespace Modules\Collections\Support;

/**
 * Central reference for the six per-tenant delinquency-restriction settings
 * (`delinquency.*` Setting slugs, tenant-scoped key-value). Mirrors
 * CollectionSettings: keeping the slugs and defaults here avoids drift
 * between the seeder, the backfill command, the config controller, and
 * DelinquencyRestrictionService.
 *
 * All defaults ship DISABLED — a tenant is never restricted until an admin
 * explicitly turns `restrictions_enabled` on.
 */
final class DelinquencySettings
{
    public const RESTRICTIONS_ENABLED = 'delinquency.restrictions_enabled';

    public const MIN_OVERDUE_AMOUNT = 'delinquency.min_overdue_amount';

    public const BLOCK_PACKAGE_INTAKE = 'delinquency.block_package_intake';

    public const BLOCK_AMENITIES = 'delinquency.block_amenities';

    public const BLOCK_VISITOR_ACCESS = 'delinquency.block_visitor_access';

    public const NOTICE_TEXT = 'delinquency.notice_text';

    public const DEFAULT_RESTRICTIONS_ENABLED = false;

    /** Minor units — 0 means any OVERDUE unit is blocked once restrictions are enabled. */
    public const DEFAULT_MIN_OVERDUE_AMOUNT = 0;

    public const DEFAULT_BLOCK_PACKAGE_INTAKE = false;

    public const DEFAULT_BLOCK_AMENITIES = false;

    public const DEFAULT_BLOCK_VISITOR_ACCESS = false;

    public const DEFAULT_NOTICE_TEXT = 'Tu unidad tiene adeudos vencidos, por lo que esta función está restringida. Ponte al corriente con tus pagos o acude a la administración para regularizar tu situación.';

    /**
     * Every delinquency.* slug and its default, for batch seeding and reads.
     *
     * @return array<string, bool|int|string>
     */
    public static function all(): array
    {
        return [
            self::RESTRICTIONS_ENABLED => self::DEFAULT_RESTRICTIONS_ENABLED,
            self::MIN_OVERDUE_AMOUNT => self::DEFAULT_MIN_OVERDUE_AMOUNT,
            self::BLOCK_PACKAGE_INTAKE => self::DEFAULT_BLOCK_PACKAGE_INTAKE,
            self::BLOCK_AMENITIES => self::DEFAULT_BLOCK_AMENITIES,
            self::BLOCK_VISITOR_ACCESS => self::DEFAULT_BLOCK_VISITOR_ACCESS,
            self::NOTICE_TEXT => self::DEFAULT_NOTICE_TEXT,
        ];
    }
}
