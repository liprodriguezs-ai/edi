<?php

namespace Modules\Collections\Support;

/**
 * Central reference for the two GLOBAL per-tenant collection settings that drive
 * the charge lifecycle. Both are Setting slugs (tenant-scoped key-value) and have
 * documented defaults used whenever a tenant has not customized the value.
 *
 *   - charge_generation_day: day-of-month the period's charge is generated (NACE).
 *   - due_soon_days: window before the due date in which a charge shows "por vencer".
 *   - grace_days: days after the due date before a charge is marked overdue (moroso).
 *
 * Keeping the slugs and defaults here avoids drift between the resolver, the cron
 * command, the seeder, the config controller, and ChargeStatusService.
 */
final class CollectionSettings
{
    public const GENERATION_DAY = 'collections.charge_generation_day';

    public const DUE_SOON_DAYS = 'collections.due_soon_days';

    public const GRACE_DAYS = 'collections.grace_days';

    public const DEFAULT_GENERATION_DAY = 1;

    public const DEFAULT_DUE_SOON_DAYS = 7;

    public const DEFAULT_GRACE_DAYS = 5;
}
