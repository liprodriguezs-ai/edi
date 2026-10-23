# Plan 005: Make `edi:migrate-legacy` transactional, retryable, and identity-safe before the production run

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**: the Screening module is UNTRACKED (uncommitted
> clinical core), so git diff cannot see it. Compare every "Current state"
> excerpt against `backend/Modules/Screening/app/Console/MigrateLegacyCommand.php`
> before starting; on any mismatch, STOP.

## Status

- **Priority**: P1 (must land BEFORE the 31-tenant production migration is executed)
- **Effort**: M
- **Risk**: LOW for the code change (offline command, idempotent by design; the fix strictly reduces partial writes). The RISK OF NOT DOING IT is the high one.
- **Depends on**: none. Sequence before any real run of `edi:migrate-legacy`.
- **Category**: bug (data integrity)
- **Planned at**: commit `dbe3682`, 2026-08-25

## Why this matters

`edi:migrate-legacy` will physically migrate ~1,836 production child-health screenings (31 medical-unit tenants) from the legacy MySQL dump into the app, re-scoring each through the real services. Three confirmed defects make the production run dangerous as-is:

1. **A failed record is skipped forever.** The per-record pipeline creates the `Visit` carrying the idempotency marker FIRST, with no transaction around the record. If `score()` or `complete()` then throws (e.g. an unmapped legacy item makes answers incomplete), the committed Visit remains — and the next run sees the marker and counts the record as `skipped`. The operator's re-run reports success while clinical records are silently missing, each leaving an orphan visit + unscored draft behind.
2. **Errors are swallowed to a 100-char warn line.** `catch (Throwable) { stats['errors']++; warn(Str::limit($e->getMessage(), 100)); }` — no stack, no persisted trace. After the console scrolls by, nothing tells you WHICH records failed or why.
3. **Practitioners are deduplicated by full name.** `User::firstOrCreate(['name' => $name])` — two distinct legacy clinicians who share a name collapse into one `User`, and every migrated screening is misattributed on the printed FUA document (which shows the practitioner's license number) and in nominal exports. `User` is a central (cross-tenant) table, so the collision even spans tenants. Name collisions in a Mexican full-name dataset of this size are near-certain, and the collapse is irreversible post-migration.

Also fixed here: the class docblock advertises a `--fresh` flag that does not exist (`$signature` has only `--center`, `--limit`, `--connection`) — someone could plan a recovery around a flag that isn't there.

## Current state

File: `backend/Modules/Screening/app/Console/MigrateLegacyCommand.php` (231 lines). Key excerpts:

```php
// :36 (docblock, LIE): "Idempotent per external key; `--fresh` re-runs from scratch on a wiped set."
// :40-43 ($signature): only --center, --limit, --connection

// :111-118 — the swallow
                foreach ($q->get() as $s) {
                    try {
                        $this->migrateScreening($conn, $s, $hc, $drafts, $answers, $scorer, $lifecycle, $somato);
                    } catch (Throwable $e) {
                        $this->stats['errors']++;
                        $this->warn("legacy {$s->id_edi_beneficiario}: ".Str::limit($e->getMessage(), 100));
                    }
                }

// :129-149 — marker-first, no transaction
    private function migrateScreening(...): void
    {
        $legacyId = (int) $s->id_edi_beneficiario;
        $marker = 'legacy:'.$legacyId;
        if (Visit::where('physical_receipt_number', $marker)->exists()) {
            $this->stats['skipped']++;
            return;
        }

        $child = $this->resolveChild($conn, (int) $s->beneficiario, $hc, $somato, $s);
        $practitioner = $this->resolvePractitioner($conn, (int) $s->usuario_creo, $hc);

        $visit = Visit::create([
            ...
            'physical_receipt_number' => $marker,
        ]);
        // then: createDraft -> answers->upsert -> scorer->score -> lifecycle->complete
        // each of those opens ITS OWN transaction; nothing wraps the record.

// :216-229 — name-keyed identity
    private function resolvePractitioner($conn, int $legacyUserId, HealthCenter $hc): PractitionerProfile
    {
        $u = $conn->table('tran_usuarios')->where('id_usuario', $legacyUserId)->first();
        $name = trim(($u->nombre ?? '').' '.($u->paterno ?? '').' '.($u->materno ?? '')) ?: ('Profesional '.$legacyUserId);

        $user = User::firstOrCreate(['name' => $name], ['auth_active' => false]);
        ...
    }
```

- The GOOD pattern already in the same file — children are keyed by a stable external id (`:185-186`): `$external = 'legacy:'.$beneficiarioId; $child = Child::where('external_id', $external)->first();`. `child.external_id` exists as a column. **`user` has NO `external_id` column** (verify: `grep -rn "external_id" backend/app/Models/User.php` → empty; check the user table migration).
- The command runs inside `$tenant->run(...)` (Stancl tenant context) per center (`:97`).
- Repo migration conventions (hard rules from AGENTS.md): every column gets `->comment('...')`; tables are commented INSIDE the create closure; UUID PKs via `BaseModel`/`HasUuids`. `User` is a central table (no `BelongsToTenant`). New migrations for the app root live in `backend/database/migrations/`.
- Tests: `grep -rn "MigrateLegacy" backend/Modules/Screening/tests` → no existing test for this command (verified 2026-08-25).
- Related known context (do not re-derive): physical-migration parity measured at 96.63% vs 99.89% offline — the divergence root-cause is the visit-date-based age recompute; that is a known, accepted property, NOT something this plan changes.

## Commands you will need

From `backend/`, dev stack up; env prefix mandatory:

```bash
ENVP="DB_HOST=127.0.0.1 DB_PORT=5451 DB_USERNAME=$USER DB_PASSWORD=password"
```

| Purpose | Command | Expected |
|---|---|---|
| New test file | `eval $ENVP php artisan test --compact Modules/Screening/tests/Feature/MigrateLegacyCommandTest.php` | all pass |
| Screening suite | `eval $ENVP php artisan test --compact Modules/Screening/tests` | all pass |
| Migration dry check | `eval $ENVP php artisan migrate --pretend` (against dev DB via compose defaults) | new migration listed, no errors |
| Format | `./vendor/bin/pint --dirty` | clean |

## Scope

**In scope**:
- `backend/Modules/Screening/app/Console/MigrateLegacyCommand.php`
- One new migration in `backend/database/migrations/` adding `external_id` to `user`
- `backend/app/Models/User.php` (add `external_id` to `$fillable` only)
- `backend/Modules/Screening/tests/Feature/MigrateLegacyCommandTest.php` (create)
- `plans/README.md` (status row)

**Out of scope** (do NOT touch):
- `LegacyParityReportCommand`, `LegacyParityComparator`, `LegacySomatometry`, the parity CSVs under `backend/storage/app/legacy/` — the parity harness is separate and correct.
- Scoring/lifecycle services — the command must keep going through the REAL services (that is the design: nothing injected, results recomputed server-side).
- Implementing `--fresh` or `--dry-run` (recorded as deferred follow-ups; this plan only makes the DOCBLOCK stop claiming `--fresh` exists).
- The 96.63% physical-parity figure and its causes.

## Git workflow

Working tree holds ~218 uncommitted files of unrelated work. No `git add`/`commit`/`stash`/branches. Leave edits uncommitted; report file list.

## Steps

### Step 1: Build the test harness with a fake legacy connection (TDD)

Create `Modules/Screening/tests/Feature/MigrateLegacyCommandTest.php`. In `setUp`, register a disposable sqlite legacy connection and create the minimal legacy tables the command reads:

```php
config(['database.connections.legacy_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
$conn = DB::connection('legacy_test');
$conn->getSchemaBuilder()->create('cat_centros_salud', function ($t) { $t->integer('id_centro_salud'); $t->string('centro_salud'); $t->string('clues')->nullable(); $t->string('domicilio')->nullable(); $t->integer('estatus')->default(1); });
// likewise: tran_edi_beneficiario (id_edi_beneficiario, beneficiario, centro_salud, usuario_creo,
//   fecha_creacion, peso, talla, perimetro_cefalico, semanas_gestacion, peso_nacer, edad_madre),
//   tran_beneficiarios (id_beneficiario, nombre, paterno, materno, fecha_nacimiento, genero, curp, estudia),
//   tran_usuarios (id_usuario, nombre, paterno, materno, cedula_profesional),
//   tran_edi_evaluacion_respuestas (edi_beneficiario, edi_evaluacion, respuesta),
//   tran_edi_frb_respuestas (edi_beneficiario, edi_frb, respuesta)
```

CAUTION: `:memory:` sqlite gives each PDO connection its own DB, but Laravel reuses one PDO per named connection within the test — acceptable here because only the command reads it. If you hit connection-reset issues, use a temp file DB instead.

Seed a legacy fixture of 2 screenings for one center whose answers map onto the `edi-legacy-2013` instrument that `RefreshDatabase` seeds (read `Modules/Screening/tests/Feature/ScreeningScoreTest.php` and the `edi-legacy-2013` seed data to learn valid legacy item ids: the command maps via `ScreeningItem.legacy_id` and option `label`). Invoke with `$this->artisan('edi:migrate-legacy', ['--connection' => 'legacy_test'])`.

Cases (1 and 2 are the reproductions and must FAIL before the fixes; 3–4 pin the fixes):

1. `test_failed_record_leaves_no_partial_state_and_is_retried_on_rerun`: make record #2 fail scoring (give it an answers row referencing a legacy item id that maps to a real item, but leave a REQUIRED item unanswered so `score()` throws incomplete). Run the command → expect stats: 1 migrated, 1 error. Assert: **no** `Visit` row with `physical_receipt_number = 'legacy:<id2>'` exists, no draft `Screening` for it. Fix the fixture (add the missing answer rows to the legacy table), run the command again → assert record #2 migrates (`screenings` total now 2, `skipped` counts only record #1). ← fails today: the orphan visit exists and the rerun reports `skipped`.
2. `test_distinct_legacy_users_with_same_name_stay_distinct`: two `tran_usuarios` rows, different `id_usuario`, identical nombre/paterno/materno, each the creator of one screening. After migration: **2** `User` rows and **2** `PractitionerProfile` rows with different `license_number`. ← fails today: 1 user.
3. `test_rerun_is_fully_idempotent`: run twice on a clean fixture → second run: `screenings: 0, skipped: 2`, and total Visit/Screening/Child counts unchanged.
4. `test_error_output_identifies_the_legacy_record`: on the failing fixture, assert the command output contains the legacy id (`expectsOutputToContain` / `doesntExpectOutput` as appropriate).

**Verify**: cases 1–2 red, 3–4 may pass or fail incidentally — record the exact red output.

### Step 2: Wrap each record in a transaction, marker included

In `handle()`'s loop, move the try/catch INSIDE a transaction boundary:

```php
                foreach ($q->get() as $s) {
                    try {
                        DB::transaction(fn () => $this->migrateScreening($conn, $s, $hc, $drafts, $answers, $scorer, $lifecycle, $somato));
                    } catch (Throwable $e) {
                        $this->stats['errors']++;
                        report($e);
                        $this->error("legacy {$s->id_edi_beneficiario} FALLÓ: {$e->getMessage()}");
                    }
                }
```

`DB::transaction` here is the app (pgsql) connection — the legacy `$conn` is read-only so it needs no transaction. The services' own `DB::transaction` calls become savepoints inside it (Laravel nests transactions via savepoints; no code change needed in the services). Because the `Visit` (with its marker) is created inside the same transaction, a mid-pipeline throw rolls the marker back — the record becomes retryable, and `resolveChild` stays idempotent via `child.external_id`. Keep `report($e)` so the full exception (stack included) lands in the log channel, and drop the `Str::limit` truncation (remove the now-unused `Str` import only if nothing else uses it).

**Verify**: Step-1 cases 1, 3 and 4 pass.

### Step 3: Key practitioner identity on the legacy user id

1. New migration in `backend/database/migrations/` (follow a sibling migration's structure):

```php
Schema::table('user', function (Blueprint $table) {
    $table->string('external_id')->nullable()->unique()
        ->comment('Stable identity key for records imported from external systems (e.g. legacy-user:<id_usuario>). Null for app-created users.');
});
```

Confirm the users table is named `user` (singular convention) by reading the existing user migration first; adjust if not. Add `'external_id'` to `User::$fillable`.

2. In `resolvePractitioner`:

```php
        $user = User::firstOrCreate(
            ['external_id' => 'legacy-user:'.$legacyUserId],
            ['name' => $name, 'auth_active' => false],
        );
```

**Verify**: `eval $ENVP php artisan migrate --pretend` lists the migration cleanly; Step-1 case 2 passes.

### Step 4: Fix the docblock and finish

Update the class docblock (`:29-37`): remove the `--fresh` claim; state what is true now — "Idempotent per external key (`child.external_id`, `user.external_id`, visit marker `legacy:<id>`); each screening migrates in one transaction, so a failed record rolls back completely and is retried on the next run. `--fresh`/`--dry-run` do not exist yet."

**Verify**: full new test file green; `eval $ENVP php artisan test --compact Modules/Screening/tests` → all pass; `eval $ENVP php artisan test --compact tests/Feature/User` → all pass (the new column must not break user flows); `./vendor/bin/pint --dirty` clean.

## Test plan

`MigrateLegacyCommandTest.php`, 4 cases (Step 1): rollback+retry, homonym practitioners, idempotent rerun, error visibility. Pattern for artisan-command testing: any existing test using `$this->artisan(...)` (search `grep -rln "artisan(" backend/tests backend/Modules/*/tests` and model the closest). The suite runs under `RefreshDatabase`, which seeds the `edi-legacy-2013` instrument the command requires — if seeding behavior differs, see STOP conditions.

## Done criteria

- [ ] All 4 new tests pass; report includes Step-1 red output for cases 1–2
- [ ] `grep -n "Str::limit" backend/Modules/Screening/app/Console/MigrateLegacyCommand.php` → empty
- [ ] `grep -n "firstOrCreate(\['name'" backend/Modules/Screening/app/Console/MigrateLegacyCommand.php` → empty
- [ ] `grep -n "fresh" backend/Modules/Screening/app/Console/MigrateLegacyCommand.php` → only the `$screening->fresh()` Eloquent call (line ~178), no flag claim
- [ ] `Modules/Screening/tests` and `tests/Feature/User` suites pass serially
- [ ] `git status` shows only: the command, one new migration, `User.php`, the new test file — beyond pre-existing unrelated changes
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back if:

- Any "Current state" excerpt mismatches the live file.
- The test database seed does NOT include the `edi-legacy-2013` instrument (the command's `firstOrFail` on the version would make every test fail for the wrong reason) — report what the seeder provides instead of hand-seeding an instrument.
- Nested-transaction behavior breaks a service call (e.g. a service relies on `afterCommit` hooks that now defer to the outer transaction and a test depends on them firing mid-record).
- The `user` table already has an `external_id` or equivalent column (someone fixed it — reconcile).
- sqlite cannot express a query the command sends to the legacy connection.
- You feel the urge to implement `--fresh`/`--dry-run` "while you're in there" — that is explicitly deferred.

## Maintenance notes

- Deferred follow-ups (record in the migration run-book, not here): implement `--dry-run` (run everything, roll back, emit the parity CSV) and `--fresh`; investigate/categorize the 3.37% physical-parity gap before any clinic signs off on migrated data; consider persisting a per-record migration log table if the run will be operated by someone other than the developer.
- `user.external_id` is a general-purpose seam: any future importer should adopt the `<source>:<id>` convention.
- Reviewer scrutiny: verify the transaction wraps the WHOLE record (child creation included) and that `report($e)` is present — the rollback without the log recreates defect #2 in a quieter form.
