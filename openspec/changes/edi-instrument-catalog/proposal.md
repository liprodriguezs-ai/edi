# Proposal: EDI Instrument Catalog

## Intent

The EDI test today exists only as a mutable legacy database plus scoring logic buried in ~200 lines of jQuery (`funcionesResultados` / `funcionesResultadosGlobal`). Nothing in the new platform knows what a "reactivo" is. This change models the instrument as **versioned, immutable, verifiable content**: age groups, items, options, risk factors, head-circumference percentile bands, and — critically — the **scoring rules as data** (`area_scoring_rule`, `global_scoring_rule`), honoring domain invariants 2 and 3. It ships the schema, seeds two frozen versions from the real production extracts, and proves integrity with `instrument:verify` in CI. Without it, `edi-scoring-engine` has nothing to evaluate and no oracle to replay legacy results against.

## Why now

- `Screening → Instrument` is the next acyclic dependency after `edi-clinic-and-child-registry` (done).
- The legacy rules exist only as JS; every day they stay untranscribed is oracle risk.
- Real extracts are already clean and verified (451 items, 29 groups, 7 risk factors, 118 percentile rows).

## Scope

### In Scope
- Backend module `Instrument`: 13 CENTRAL tables (no `tenant_id`), all content keyed by `instrument_version_id`.
- Immutability: observer rejecting writes on published versions; SHA-256 `content_hash`; deterministic UUIDv5 identities; `legacy_id` traceability (never exposed).
- Seeding of two versions from committed CSVs: `edi-legacy-2013` (bug-compatible, frozen oracle reference) and `edi-2022-2ed` (`is_default`).
- Hand-written scoring-rule CSVs (area ≈151/version, global ≈59/version) transcribed from the legacy JS + manual.
- `artisan instrument:verify {version}` command, run in the test suite (CI).
- Module registration under **Core** (super admin only) + read-only API + read-only React inspector.

### Out of Scope
- The scoring engine, screening application flow, historical import (`edi-scoring-engine` and later changes).
- `edi-2024` version content (loaded when PDFs exist).
- Any tenant-scoped or admin-editable instrument content.

## Capabilities

### New Capabilities
- `instrument-catalog`: the EDI instrument as versioned immutable content — structure, item bank, risk factors, percentile reference, and scoring rules as data.

### Modified Capabilities
- None.

## Approach

Clone house patterns (`BaseModel::columns($table, false)`, fixed-UUID central catalogs via `CatalogSeeder::upsertCatalog`, no DB FKs, partial unique indexes). Content rows get UUIDv5 from `(version, table, natural key)` so ids match across dev/CI/prod and re-seeding is idempotent. Publishing computes the hash; re-seed aborts loudly on drift. Rules use a closed 6-operator jsonb grammar, first-match-wins.

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Rule transcription errors vs legacy JS | Med | Hand-written CSVs reviewed line-by-line; verbatim JS transcription in design; oracle replay later |
| Group 15 has no legacy engine (228 prod tests exist) | High | Seed extrapolated G15 rules tagged `source=extrapolated` (design D14, Q1) |
| Legacy anomalies (CO-R code, sentinel ranges, ALE+FRB scope bug) silently mishandled | Med | Each one is an explicit design decision + verify check + spec scenario |
| Content drift after publish | Low | Observer + content hash + verify in CI |

## Rollback Plan

New tables and additive seeder/registration rows only; no existing table is altered. Revert the PR(s); `migrate:rollback` drops the tables; re-running seeders after revert removes menu/permission rows. Downstream modules do not exist yet, so nothing consumes this content.

## Dependencies

- `edi-clinic-and-child-registry` (central `sex` catalog referenced by percentile rows).
- Raw legacy extracts at `/Users/rodriguez/.claude/jobs/1d2c6bff/tmp/instrument/` (copied into the repo in Phase 3 before they expire).

## Success Criteria

- [ ] `migrate:fresh --seed` creates both versions published, hashed, with exact expected row counts (29 groups, 451 items, 902 options, 7 risk factors, 118 percentile rows, 151 area rules, 59 global rules, 4 messages per version).
- [ ] `instrument:verify` passes for both versions and runs green inside `php artisan test`.
- [ ] Re-seeding is a no-op; mutating published content throws; tampered content fails hash verification.
- [ ] Super admin sees the read-only inspector; tenant roles get 403; no instrument entry exists in `$mapperSelects`/`CatalogType`.

## Proposal question round

Interactive round unavailable in this executor context — defaults are encoded in `design.md` §Open decisions; correct them before `sdd-apply`.

1. **Group 15 rules**: legacy has no `funcionesResultados[15]` (nor `resultados15.phtml`), yet 228 production tests carry G15 results. Default: seed G15 rules extrapolated from the G9–14 pattern, tagged `source=extrapolated`, in BOTH versions.
2. **ALE+FRB scope bug**: legacy sums alert signs + risk factors only in groups 1–4; groups 5–14 ignore them. Default: reproduce faithfully in BOTH versions (do not invent clinical thresholds without the manual); flagged for the 2022/2024 manual review.
3. **Percentile coverage**: legacy table covers months 1–59 only (G15, 60–71, has none — consistent with EN having 2 items in G15). Default: keep 1–59 in both versions.
4. **Alternate adjudication**: the legacy block that scored alternate evaluations is commented out. Default: still seed per-area alternate rules from those commented thresholds, tagged `source=legacy_js_commented`; the engine change decides how to use them.
5. **CO-R anomaly**: group 12A codes its 3 knowledge items `CO-R n`. Default: normalize their area to CO at seed time, keep the verbatim code.
