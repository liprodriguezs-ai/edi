<?php

use App\Support\TimestampZoneConverter;
use Illuminate\Database\Migrations\Migration;

/**
 * One-off data conversion: storage moved from the legacy wall clock
 * (APP_TIMEZONE=America/Mexico_City) to UTC. Every `timestamp without time
 * zone` column is re-interpreted from `app.legacy_storage_timezone` to UTC
 * with Postgres tz rules (pre-2022 Mexican DST included). `date` columns are
 * civil dates and stay untouched.
 *
 * No-op when LEGACY_STORAGE_TIMEZONE is unset, empty or UTC (fresh installs,
 * tests, data already in UTC). The migrations ledger is the idempotency
 * mechanism: it runs once per database.
 */
return new class extends Migration
{
    public function up(): void
    {
        $zone = $this->legacyZone();
        if ($zone !== null) {
            app(TimestampZoneConverter::class)->convert($zone, 'UTC');
        }
    }

    public function down(): void
    {
        $zone = $this->legacyZone();
        if ($zone !== null) {
            app(TimestampZoneConverter::class)->convert('UTC', $zone);
        }
    }

    private function legacyZone(): ?string
    {
        $zone = config('app.legacy_storage_timezone');

        return is_string($zone) && $zone !== '' && $zone !== 'UTC' ? $zone : null;
    }
};
