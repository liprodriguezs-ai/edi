<?php

namespace App\Support;

/**
 * SpreadsheetSafe — de-fangs values that would be interpreted as a formula
 * by the spreadsheet application that opens an exported .xlsx.
 *
 * Classic "CSV/formula injection": a free-text field such as
 * `=HYPERLINK("http://evil","click")` or `=cmd|'/c calc'!A1`, once written
 * verbatim into a cell, executes inside the READER's spreadsheet client
 * (Excel/LibreOffice/Google Sheets) — not on our server. Prefixing a leading
 * formula-trigger character with an apostrophe forces every major
 * spreadsheet client to render the cell as literal text instead.
 */
class SpreadsheetSafe
{
    /**
     * Leading characters a spreadsheet client treats as "this cell is a
     * formula". @ and +/- are included alongside = because Excel accepts
     * them as formula-leading characters too; tab/CR are included because
     * they can be used to smuggle a payload past naive downstream parsers.
     *
     * @var list<string>
     */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Return $value unchanged unless its first character is a formula
     * trigger, in which case it is prefixed with an apostrophe.
     */
    public static function cell(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (in_array($value[0], self::DANGEROUS_PREFIXES, true)) {
            return "'".$value;
        }

        return $value;
    }
}
