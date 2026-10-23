<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use QCH\Core\Support\TenantTime;

/**
 * Re-interprets every offset-less instant column of the current schema from
 * one zone to another, using PostgreSQL's tz database (so historical DST, such
 * as Mexico's pre-2022 summer time, is applied per row).
 *
 * `date` columns are civil dates and are never touched. This is a
 * schema-level maintenance tool for migrations, not tenant business logic:
 * it deliberately runs below Eloquent and therefore below TenantScope.
 */
final class TimestampZoneConverter
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /**
     * Shifts the stored wall clock from $from to $to, ONE UPDATE per table.
     *
     * @param  list<string>|null  $tables  restrict to these tables (null = every table of the schema)
     */
    public function convert(string $from, string $to = 'UTC', ?array $tables = null): void
    {
        foreach ([$from, $to] as $zone) {
            if (! TenantTime::isValidZone($zone)) {
                throw new InvalidArgumentException("La zona horaria «{$zone}» no es válida.");
            }
        }
        if ($from === $to) {
            return;
        }

        $columnsByTable = $this->timestampColumns();
        if ($tables !== null) {
            $columnsByTable = array_intersect_key($columnsByTable, array_flip($tables));
        }

        foreach ($columnsByTable as $table => $columns) {
            $assignments = [];
            $bindings = [];
            foreach ($columns as $column) {
                $quoted = $this->quote($column);
                // NULL AT TIME ZONE x is NULL, so null values stay null without a WHERE per column.
                $assignments[] = "{$quoted} = ({$quoted} AT TIME ZONE ?) AT TIME ZONE ?";
                array_push($bindings, $from, $to);
            }

            DB::update('UPDATE '.$this->quote($table).' SET '.implode(', ', $assignments), $bindings);
        }
    }

    /**
     * Every `timestamp without time zone` column of the current schema's base tables.
     *
     * @return array<string, list<string>> table => columns (ordinal order)
     */
    public function timestampColumns(): array
    {
        $rows = DB::select(
            "SELECT c.table_name, c.column_name
               FROM information_schema.columns c
               JOIN information_schema.tables t
                 ON t.table_schema = c.table_schema AND t.table_name = c.table_name
              WHERE c.table_schema = current_schema()
                AND t.table_type = 'BASE TABLE'
                AND c.data_type = 'timestamp without time zone'
              ORDER BY c.table_name, c.ordinal_position"
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row->table_name][] = (string) $row->column_name;
        }

        return $columns;
    }

    private function quote(string $identifier): string
    {
        if (! preg_match(self::IDENTIFIER, $identifier)) {
            throw new InvalidArgumentException("El identificador «{$identifier}» no es válido.");
        }

        return '"'.$identifier.'"';
    }
}
