---
name: dataimport-status-contract
description: The async payment-import polling seam — GET /import/{id}/status shape, status/phase unions, report keys, error path. Verify before reviewing DataImport changes.
metadata:
  type: project
---

# DataImport async status contract (import-bulk-performance)

Seam: `ConfirmStep.tsx` polls `GET /import/{id}/status` every 2s until terminal status.

**Backend source of truth:**
- `backend/Modules/DataImport/app/Models/ImportRun.php` — `STATUSES` = pending, processing, ready, completed_with_errors, failed. `PHASES` = reading_file, processing, finalizing, done.
- `ImportController::status()` returns `{ status, phase, progress, report, error }` (NOT a full model resource — no id/file_path/timestamps).
- Report keys (final, written by `FinalizeImportJob` + chunk jobs): `total, units_created, residents_created, payments_registered, payments_skipped, failed_count, succeeded_count, bank_balance_delta[], rows[]`. The interim keys `bank_deltas` + `affected_unit_ids` are STRIPPED before the terminal save (so they only exist mid-poll, not in the final report).
- `/import/validate` returns a DIFFERENT shape: `{ total, units_to_create, residents_to_create, payments_to_register, blocked, rows[] }` (the *_to_create naming is validate-only; execute report uses *_created).

**Frontend consumer:** `frontend/src/modules/import/schema.ts` (`IImportRun`, `IImportReport`, `IValidateResponse`, `ImportRunStatus`, `ImportPhase`).

**Verified aligned (2026-06-14):** status union, phase union, all report keys, progress shape (0/0 handled via isIndeterminate), error path (`run.error` → failed banner). FE union types match backend constants exactly. No `any`. The `pending`/`processing` non-terminal statuses keep polling (no stuck-spinner gap). Validate vs execute report shapes are correctly kept as two distinct interfaces.

**Watch on future changes:** if anyone adds a new `ImportRun` status/phase constant, the FE union + `ConfirmStep` switch must both be updated or the UI silently keeps spinning. The status endpoint is hand-rolled JSON (not an Orion resource), so it will NOT auto-track model column changes.
