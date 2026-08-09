# Plan 059: De-fang spreadsheet formulas on export and bound the synchronous import-validate

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- Modules/DataImport/app/Services/WorkbookReader.php Modules/DataImport/app/Http/Controllers/ImportController.php Modules/Package/app/Services/PackageReportService.php`

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW–MED
- **Depends on**: none (complements plan 057's phpspreadsheet bump)
- **Category**: security + performance
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

Two hardening gaps on the spreadsheet path:

1. **CSV/formula injection.** Import reads trim cells but do not de-fang leading
   `= + - @`; those values (`notes`, `reference`, `recipient_name`) are stored verbatim.
   Exports (`PackageReportService::agingExportRows`, and other export column mappers) emit
   raw cells with no escaping. A guard-entered package `description`/`tracking_number` or
   an imported `notes` like `=HYPERLINK(...)` / `=cmd|...` is re-emitted into an `.xlsx`
   an admin/board member later opens, where the client may execute the formula — classic
   CSV injection reaching a privileged reader.

2. **Unbounded synchronous validate.** `POST /import/validate` parses the ENTIRE uploaded
   workbook into a PHP array in-request (`WorkbookReader::parse` → `Excel::toArray`), on a
   long-lived Octane worker whose memory is shared across requests. A `.xlsx` is a zip;
   10 MB (the only cap) of repetitive XML expands to hundreds of MB of PHP-array cells, so
   a few concurrent/crafted validations can exhaust worker memory — a low-effort DoS. (The
   chunked design only applies to EXECUTE, not validate.)

## Current state

- `backend/Modules/DataImport/app/Services/WorkbookReader.php`:
  - `parse()` (lines 66–68): `Excel::toArray(new \stdClass, $filePath, null, ...XLSX)` —
    materializes every cell.
  - `normalizeCell` (≈166–179): trims only; leading `= + - @` pass through.
- `backend/Modules/DataImport/app/Http/Controllers/ImportController.php` `validateImport`
  (lines 75–90): calls `$reader->parse($filePath)` synchronously in the HTTP path; docblock
  (line 68) literally says "synchronous dry-run validation." Upload cap `max:10240` (10 MB)
  in `ImportValidateRequest`/`ImportExecuteRequest`.
- `backend/Modules/Package/app/Services/PackageReportService.php` (≈103–127,
  `agingExportRows`): builds `carrier`, `description`, `tracking_number`, `storage_location`
  cells with no escaping. (Repo-wide grep found no CSV/formula sanitizer.)

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Syntax | `cd backend && php -l Modules/DataImport/app/Services/WorkbookReader.php` | clean |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=Import` and `--filter=Package` | all pass incl. new cases |

## Scope

**In scope**:
- A shared export cell-sanitizer (one helper) applied by every export column mapper
  (`PackageReportService` now; other export services as they surface).
- Optionally de-fang on import too (prefix-strip in `normalizeCell`).
- Bound `/import/validate`: cap rows read for the dry-run (read first N rows via a read
  filter / row limit) OR make validate async like execute. Prefer a bounded row cap so
  validate stays a fast in-request preview.

**Out of scope**:
- The EXECUTE chunked pipeline (already queued + chunked at 200/job).
- The phpspreadsheet version bump (plan 057) — complementary, do that too.
- Non-spreadsheet exports (PDF).

## Steps

### Step 1: Add a shared formula de-fang helper

Add a small helper (e.g. `App\Support\SpreadsheetSafe::cell(string $value): string`) that
prefixes a value whose first char is one of `= + - @ \t \r` (tab/CR) with a leading
apostrophe (`'`), so spreadsheet clients treat it as literal text.

**Verify**: unit test the helper: `=cmd` → `'=cmd`, `hola` → `hola`, `` (empty) → ``.

### Step 2: Apply it at every export cell mapper

Route `carrier`, `description`, `tracking_number`, `storage_location`, and any free-text
export column through the helper in `PackageReportService::agingExportRows` (and any other
export mapper the grep from Step 4 finds).

**Verify**: `php artisan test --filter=Package` passes; add a test asserting an
`=HYPERLINK(...)`-containing field is emitted apostrophe-prefixed.

### Step 3: Bound the validate dry-run

In `WorkbookReader` (or a dedicated bounded read path used by `validateImport`), cap the
number of data rows materialized for validation (e.g. first 1,000 rows, config-driven),
or switch validate to a read filter that streams. Keep EXECUTE's full chunked read
unchanged. If capping, surface a clear message when the file exceeds the preview cap
("validado las primeras N filas") so the admin isn't misled.

**Verify**: a test with a large fixture asserts validate does not materialize the whole
file (or asserts the row cap message). `php artisan test --filter=Import` green.

### Step 4: Sweep for other export mappers

`grep -rn "fromArray\|->cells\|WithHeadings\|toArray" backend/Modules --include=*.php | grep -i export`
and route any free-text export cells through the helper.

**Verify**: no free-text export column bypasses the sanitizer.

### Step 5: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] Shared de-fang helper exists + unit-tested
- [ ] Package aging export escapes formula-leading cells (test proves it)
- [ ] `/import/validate` is bounded (row cap or async) — test proves whole-file is not
      materialized in-request
- [ ] `php artisan test --filter=Import` and `--filter=Package` green
- [ ] `php -l` clean, `vendor/bin/pint --dirty` exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- Any excerpt differs (drift).
- Bounding validate to N rows makes an existing "validate reports total row count" test
  fail — decide whether the count is essential; if so, read only the count cheaply
  (metadata) rather than all cells, and report the approach.
- maatwebsite/excel's version (post plan-057 bump) changes the reader API used here —
  adapt and note.

## Maintenance notes

- Every NEW export must use the shared sanitizer — call it out in review of any export PR.
- Reviewer: confirm import de-fang (if added) doesn't corrupt legitimate values that start
  with `-` (negative numbers stored as text) — prefer escaping only on EXPORT if that risk
  is real for this data.
- If validate is capped, the execute path remains the source of truth for full validation.
