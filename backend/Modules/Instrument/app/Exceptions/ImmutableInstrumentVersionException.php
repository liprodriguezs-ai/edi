<?php

namespace Modules\Instrument\Exceptions;

use RuntimeException;

/**
 * Thrown by InstrumentContentObserver / InstrumentVersionObserver when code
 * attempts to create/update/delete/restore a row whose instrument_version is
 * already published (design D5 / REQ-INS-002). A new edition is always a NEW
 * version; there is no valid path to mutate published content.
 */
class ImmutableInstrumentVersionException extends RuntimeException
{
    public static function forContent(string $table, string $versionKey): self
    {
        return new self(
            "Cannot modify '{$table}': its instrument_version ('{$versionKey}') is already published. "
            .'Published content is immutable -- create a new version instead.'
        );
    }

    public static function forVersionField(string $field, string $versionKey): self
    {
        return new self(
            "Cannot modify '{$field}' on instrument_version '{$versionKey}': it is already published. "
            .'Only is_default may change after publish.'
        );
    }
}
