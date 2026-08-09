# Plan 057: Patch the critical phpspreadsheet CVE (and the guzzle/psr7/jmespath mediums)

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `cd backend && composer audit` — confirm the advisories below are still reported before
> acting; if the list changed, adapt to the current output.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW–MED
- **Depends on**: none (but re-run DataImport/export tests — see plan 063 for wiring)
- **Category**: security (dependencies)
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

`composer audit` reports a **CRITICAL** advisory: `phpoffice/phpspreadsheet`
CVE-2026-45034 ("patch bypass for CVE-2026-34084"), affecting `<= 1.30.4`; the installed
version is exactly `1.30.4`. PhpSpreadsheet backs `maatwebsite/excel`, which parses
tenant-admin-uploaded `.xlsx` files in DataImport and builds the aging/report exports —
i.e. attacker-influenced bytes reach the vulnerable parser. Four MEDIUM advisories also
sit on the outbound-HTTP path used by Stripe, Firebase, and the Facebook CAPI channel:
`guzzlehttp/guzzle` (2), `guzzlehttp/psr7` (CRLF injection), `mtdowling/jmespath.php`
(code injection).

## Current state

- `backend/composer.json` `require` (lines 25–59): `maatwebsite/excel: "^3.1"` (line 41)
  pulls `phpoffice/phpspreadsheet` transitively — there is NO direct phpspreadsheet
  constraint, so it is bumped as a transitive dependency. guzzle/psr7 come in transitively
  via Cashier/Firebase/HTTP client; jmespath via the AWS SDK (flysystem-s3).
- `composer audit` (measured this cycle): 5 advisories / 4 packages — 1 CRITICAL
  (phpspreadsheet), 4 MEDIUM (guzzle ×2, psr7, jmespath).
- Tests run on host php against the shared Postgres `testing` DB (repo CLAUDE.md).
- CI: `.github/workflows/test.yml` runs `php artisan test --parallel` on push — so once
  the DataImport module tests are wired (plan 063) they gate this bump automatically.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Audit | `cd backend && composer audit` | after the fix: phpspreadsheet CRITICAL gone |
| Update | `cd backend && composer update phpoffice/phpspreadsheet guzzlehttp/guzzle guzzlehttp/psr7 mtdowling/jmespath.php --with-dependencies` | resolves to patched versions |
| Show | `cd backend && composer show phpoffice/phpspreadsheet` | version > 1.30.4 |
| Test (import/export) | `cd backend && php artisan test --filter=Import` and `--filter=Package` and `--filter=Report` | all pass |

## Scope

**In scope**:
- `backend/composer.json` (only if a constraint must change) and `backend/composer.lock`.

**Out of scope**:
- Application code — this is a dependency bump. If a bump forces an API change in our
  code, STOP and report (it becomes a separate plan).
- Frontend dependencies (separate — plan 058).

## Steps

### Step 1: Confirm the advisories

`cd backend && composer audit`. Record the current list. Confirm phpspreadsheet 1.30.4 is
installed (`composer show phpoffice/phpspreadsheet`).

**Verify**: you see the CRITICAL phpspreadsheet advisory.

### Step 2: Update the vulnerable packages

`composer update phpoffice/phpspreadsheet guzzlehttp/guzzle guzzlehttp/psr7 mtdowling/jmespath.php --with-dependencies`.

If `maatwebsite/excel: ^3.1` pins phpspreadsheet below the patched line, check whether a
`maatwebsite/excel` bump (still within a safe range) is needed; if so, do that too and
note it. Do NOT loosen constraints blindly.

**Verify**: `composer audit` no longer lists phpspreadsheet CRITICAL; `composer show phpoffice/phpspreadsheet` reports a version above 1.30.4.

### Step 3: Prove the Excel paths still work

Run the import and export tests: `php artisan test --filter=Import`, `--filter=Package`
(aging export), `--filter=Report`. If DataImport's tests don't run under `--filter` (not
wired into phpunit — plan 063), at minimum run the Package/Report export tests and note
the DataImport gap.

**Verify**: the Excel-touching tests pass on the new versions.

## Done criteria

- [ ] `composer audit` no longer reports the phpspreadsheet CRITICAL
- [ ] guzzle/psr7/jmespath advisories cleared (or, if a clean version isn't available yet,
      documented with the blocker)
- [ ] `composer show phpoffice/phpspreadsheet` > 1.30.4
- [ ] Import/export tests pass on the new versions
- [ ] Only `composer.json`/`composer.lock` changed (no app code) — `git -C backend status`
- [ ] `plans/README.md` row updated

## STOP conditions

- A bump requires an application-code change (PhpSpreadsheet/Guzzle API break) — STOP and
  report; scope the code change separately.
- No patched version exists yet for a MEDIUM advisory — patch phpspreadsheet (the
  critical) anyway and note the remaining mediums.
- `composer update` pulls a major bump of an unrelated package — narrow the update to the
  named packages (`--with-dependencies`, not `composer update` bare).

## Maintenance notes

- Add `composer audit` as a CI step (fold into plan 063's CI work) so a future critical
  advisory fails the build.
- Reviewer: confirm no `require` constraint was loosened beyond what the patch needs.
- The Excel parser is attacker-reachable via DataImport — keep this dependency current.
