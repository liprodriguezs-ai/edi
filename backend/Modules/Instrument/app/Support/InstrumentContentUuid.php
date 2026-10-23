<?php

namespace Modules\Instrument\Support;

use Ramsey\Uuid\Uuid;

/**
 * Deterministic UUIDv5 identity for versioned instrument content (design D6).
 *
 * Same {version_key}/{table}/{natural_key} ALWAYS produces the same id, in
 * dev/CI/prod. This is what makes re-seeding idempotent by construction and
 * the committed CSVs diffable: the id is a pure function of the data, never
 * a random value nor an auto-increment.
 */
final class InstrumentContentUuid
{
    public const NAMESPACE = 'ed1c0000-0000-4000-8000-000000000001';

    /**
     * @param  string  $versionKey  e.g. 'edi-legacy-2013'
     * @param  string  $table  e.g. 'screening_item'
     * @param  string  $naturalKey  e.g. 'G1/MG1.2' (design §4 per-table natural keys)
     */
    public static function for(string $versionKey, string $table, string $naturalKey): string
    {
        $name = "{$versionKey}/{$table}/{$naturalKey}";

        return Uuid::uuid5(self::NAMESPACE, $name)->toString();
    }
}
