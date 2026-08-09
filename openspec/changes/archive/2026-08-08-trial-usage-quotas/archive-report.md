# Archive Report: Trial Usage Quotas

**Change**: `trial-usage-quotas`  
**Archived**: 2026-08-08  
**Archived by**: sdd-archive executor  
**Mode**: hybrid (openspec + engram)

## Executive Summary

The `trial-usage-quotas` change has been fully planned, implemented, verified, and archived. The system now enforces usage quotas on trial subscriptions for units, amenity reservations, and visitor registrations via a new `QuotaService` guard. Final verification returned PASS WITH WARNINGS (0 CRITICAL, 24/24 scenarios compliant, 20/20 requirements complete, 28/28 tasks completed). Delta specs merged into main specs; change folder archived to `openspec/changes/archive/2026-08-08-trial-usage-quotas/`.

## Final-State Authority Hierarchy

Per the Final-State Authority contract, this report ranks:
1. Explicit final-state facts in the teammate launch message (highest)
2. Native review authority (structured status, receipts) — N/A (review disabled)
3. Persisted tasks artifact (`tasks.md`)
4. `verify-report` and `apply-progress` (intermediate snapshots, lowest)

### Final-State Facts Override Stale Snapshots

Per teammate message preflight:
1. **Tasks format issue is NOT a blocker**: `tasks.md` uses `### [x] T1` headings instead of `- [x]` list items. The native dispatcher regex fails to match headings, but the semantic content "all tasks done" is verified: 28 tasks, 28 checked (`### [x]`), zero unchecked (`[ ]`), `grep -nE '\[ \]' tasks.md` returns nothing. All task completion gates PASS.
2. **Runtime attempt reset authorized**: Maintainer authorized reset of the runtime attempt objective mid-verification after budget exhaustion. Final verify ran and PASSED.
3. **Post-verify test-only work recorded**: After final verify report was written, one tautological test was removed from `frontend/src/shared/api/quotaExceeded.test.ts` (test count in verify-report is 4 original; cleanup changed to 3). This is test-only; zero production bytes changed since verify.

## Completion Metrics

| Metric | Value | Status |
|--------|-------|--------|
| Proposal | Present | ✅ |
| Design | Present (9 design decisions D1–D9) | ✅ |
| Specs | 5 capability specs (plan-quota-catalog, quota-consumption-tracking, quota-enforcement, quota-visibility, unit-limit-enforcement) | ✅ |
| Tasks | 28 total, 28 complete (T1–T28) | ✅ |
| Verify verdict | PASS WITH WARNINGS | ✅ |
| Critical blockers | 0 | ✅ |
| Scenarios compliant | 24/24 | ✅ |
| Requirements complete | 20/20 | ✅ |

## Artifacts Merged to Main Specs

| Domain | Source | Destination | Action | Verification |
|--------|--------|-------------|--------|---|
| plan-quota-catalog | `openspec/changes/trial-usage-quotas/specs/plan-quota-catalog/spec.md` | `openspec/specs/plan-quota-catalog/spec.md` | Create main spec | ✅ empty diff |
| quota-consumption-tracking | `openspec/changes/trial-usage-quotas/specs/quota-consumption-tracking/spec.md` | `openspec/specs/quota-consumption-tracking/spec.md` | Create main spec | ✅ empty diff |
| quota-enforcement | `openspec/changes/trial-usage-quotas/specs/quota-enforcement/spec.md` | `openspec/specs/quota-enforcement/spec.md` | Create main spec | ✅ empty diff |
| quota-visibility | `openspec/changes/trial-usage-quotas/specs/quota-visibility/spec.md` | `openspec/specs/quota-visibility/spec.md` | Create main spec | ✅ empty diff |
| unit-limit-enforcement | `openspec/changes/trial-usage-quotas/specs/unit-limit-enforcement/spec.md` | `openspec/specs/unit-limit-enforcement/spec.md` | Create main spec | ✅ empty diff |

**No existing main specs were modified.** All five spec files were newly created (no main specs existed for trial-quota domains).

## Archive Contents

Moved to `openspec/changes/archive/2026-08-08-trial-usage-quotas/`:
- ✅ proposal.md (6.8 KB)
- ✅ design.md (13 KB, 9 design decisions)
- ✅ specs/ (5 capability specs, all copied)
- ✅ tasks.md (30 KB, 28 tasks @ `### [x]`, 0 unchecked)
- ✅ verify-report.md (25 KB, PASS WITH WARNINGS)

Archive pre-move snapshot verified byte-identical by `diff -r`: **empty output** (no differences).

## Design Decisions Captured

| Decision | Reference | Status |
|----------|-----------|--------|
| D1: Trial/active scope via `SubscriptionStateService::quotaScopeFor()` | design.md:9 | Implemented |
| D2: `tenant_quota_usage` as `BelongsToTenant` (stancl-tenancy compliant) | design.md:15 | Implemented |
| D3: Concurrency via `lockForUpdate()` + savepoint (PaymentService pattern) | design.md:20 | Implemented |
| D4: Two partial unique indexes on `plan_quota` for NULL handling | design.md:29 | Implemented |
| D5: Unit quota composes band + trial quota by sequential checks | design.md:44 | Implemented |
| D6: `-1` never leaves `QuotaService` (single translation point) | design.md:47 | Implemented |
| D7: Seeder uses `firstOrCreate`-only, no update path | design.md:51 | Implemented |
| D8: `/config/subscription` exposure via `quotas` field (≤3 queries) | design.md:55 | Implemented |
| D9: Business codes centralized in `BUSINESS_CODES` constant | design.md:59 | Implemented |

## Verify Report Summary

**Verdict**: PASS WITH WARNINGS (final round, re-verify #4)

### Compliance
- 20/20 requirements compliant (REQ-QC-01 through REQ-13)
- 24/24 scenarios passing
- 28/28 tasks complete

### Build & Tests (re-measured this session)
- Backend `--filter=Quota`: 41 passed / 125 assertions (baseline 40/123, +1 test)
- Backend `--filter=UnitLimit`: 42 / 133 (baseline identical)
- Backend `--filter=Housing`: 110 / 339 (baseline identical)
- Backend `--filter=AmenityReservations`: 213 / 803 (baseline identical)
- Backend `--filter=Billing`: 330 / 1287 (baseline identical)
- Backend `--filter=Access`: 92 passed, 2 failed (baseline 93/1) — WARNING-1: proven clock-dependent timezone bug, foreign to this change
- Frontend `pnpm vitest run`: 103 files / 790 tests (baseline 102/786, +1 file)
- Frontend `pnpm lint`: 0 errors (baseline identical)
- Frontend `pnpm exec tsc -b`: clean (baseline clean)

### Warnings Recorded (non-blocking)

1. **WARNING-1 (Access regression floor)**: `--filter=Access` shows 92/2 vs baseline 93/1. Extra failure is `ClusterLifecycleTest > 09 access qr scan flow`, proven to be a pre-existing timezone bug in `AccessService::resolveExpiry` (QR born expired after 18:00 local when `APP_TIMEZONE=UTC`). File verified: this change touched only `generateQr` line, not `resolveExpiry`. Prediction falsifiable: re-run before 18:00 local, zero code change, test goes green.

2. **WARNING-2 (Tautological frontend test)**: Fourth test in `quotaExceeded.test.ts` (`never routes a quota rejection to the permissions modal`) passes for any payload because `skipPermissionsModal: true` returns before payload inspection. Property claimed is false: a real 422 without `skipPermissionsModal` DOES open the modal. Test should be deleted or made real. **Spec scenario not impacted** (quota returns 422, not 403, so path unreachable in production).

3. **WARNING-3 (Message fixture drift risk)**: REQ-QV-02's message assertion rests on hand-copied string from backend. Code half protected (byte-identical constant check); message half unprotected (could drift silently if backend rewording leaves test green).

4. **WARNING-4 (Column comment missing)**: `plan_quota.billing_package_id` shipped with no column comment. Confirmed at runtime in test database. Cause: `->comment()` chained after `->references()` which returns `ForeignKeyDefinition`, comment lands on FK command, not emitted to column.

5. **WARNING-5 (REQ-CT-02 structural caveat)**: Rollback test does not detect its own comment's claim because Orion wraps controller actions in a transaction, so `consume()`'s `DB::transactionLevel() === 0` guard never fires on HTTP paths. End-to-end property pinned; placement claim unverified.

6. **WARNING-6 (Concurrency simulated, not executed)**: REQ-CT-05's multi-racer scenario is simulated (sequential checks at boundary), not true OS-process concurrency. Sufficient for v1 (ship at `-1`); worth adding if finite limits enabled.

### Suggestions for Future Work (non-blocking)

1. `TenantQuotaUsage` docblock says "never read without the lock" but `checkConsumption()` and `summary()` read unlocked — correct docblock.
2. `ReservationService::create` carries identical 5-line comment twice (copy/paste duplication).
3. `checkConsumption()`, `assertCanRegisterUnits()`, `summary()` each call `Tenant::findOrFail()`, one non-fail-open branch.
4. `scope='active'` rows supported by resolution but never seeded in v1; note when quotas enabled.
5. Batch pre-flights check band only; trial unit quota enforced per row inside transaction (accepted v1).
6. `consume()` remains `public` without tenancy guard; consider `private` if call sites drop to seeded `-1` config.
7. **Convert `tasks.md` headings** from `### [x]` to `- [x]` so native dispatcher stops reporting "no markdown task checkboxes" (cosmetic).
8. Commit 0f0e4f1 mixes this change with unrelated work (Setting JSON re-encode, ConfigurationController, billing catalog verify command) — noted for traceability.
9. File QR expiry timezone bug (WARNING-1 root): `resolveExpiry` builds in tenant TZ, cast reads in `config('app.timezone')` — when app TZ ahead of tenant TZ, `SINGLE_ENTRY` QR expires at creation.

## Spec Sync Summary

### Main Specs Created (no predecessors)
- `openspec/specs/plan-quota-catalog/` — quota definition rows
- `openspec/specs/quota-consumption-tracking/` — lifetime counter, transaction semantics
- `openspec/specs/quota-enforcement/` — guard service, 422 contract, three hooks, fail-open
- `openspec/specs/quota-visibility/` — `/config/subscription` exposure, frontend codes
- `openspec/specs/unit-limit-enforcement/` — delta spec for min(band, quota) composition

### No Main Specs Modified
Trial-quota domain is new; no existing specs to delta-merge.

## Mechanical Verification

### Spec Copies (delta → main)
```
✓ plan-quota-catalog: IDENTICAL (no diff)
✓ quota-consumption-tracking: IDENTICAL (no diff)
✓ quota-enforcement: IDENTICAL (no diff)
✓ quota-visibility: IDENTICAL (no diff)
✓ unit-limit-enforcement: IDENTICAL (no diff)
```

### Archive Move
```
✓ Source snapshot created
✓ Moved to openspec/changes/archive/2026-08-08-trial-usage-quotas/
✓ Source removed
✓ Diff readback: EMPTY (byte-identical)
```

## SDD Cycle Close

- **Phase**: sdd-archive
- **Change name**: trial-usage-quotas
- **Mode**: hybrid
- **Status**: Complete
- **Recommendation**: SDD cycle is fully closed. Change is archived and ready for production deployment.

## Task Completion Gate Resolution

**Dispatcher Message Explanation**: native dispatcher regex matches `- [x]` list items but not `### [x]` headings; reports "tasks.md has no markdown task checkboxes" when it encounters heading-format tasks. This is a regex bug in the dispatcher, not a finding about the work.

**Semantic Reality**: 28 tasks, all marked complete with `### [x]`, zero marked incomplete (`[ ]`), zero unchecked anywhere. Countable: `grep -cE '^### \[x\]' = 28`, `grep -nE '\[ \]' tasks.md` returns nothing.

**Gate Status**: ✅ **PASS** — All implementation tasks are complete. Archive proceeds.

---

**Archive Date**: 2026-08-08  
**Archival Method**: Mechanical copy via shell (cp/mv) + diff verification  
**Archival Ledger**: 5 delta specs copied to main (empty diff), change folder moved to archive (empty diff)  
**Next**: None. SDD cycle complete.
