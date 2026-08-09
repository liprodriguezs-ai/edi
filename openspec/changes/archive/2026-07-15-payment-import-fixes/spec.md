# Spec — payment-import-fixes

**Change:** `payment-import-fixes`
**Module:** `Modules/DataImport` + `frontend/src/modules/import` + `frontend/src/modules/payment` + `frontend/src/modules/charge`
**Status:** spec
**Date:** 2026-07-14
**Based on proposal:** `openspec/changes/payment-import-fixes/proposal.md`

---

## Overview

This spec describes what MUST be true after `payment-import-fixes` is applied. It covers two capabilities: **import-entry-and-menu** (where the import wizard is reachable) and **import-validation-feedback** (how `/import/validate` reports issues and counters). It does NOT prescribe implementation; see the design artifact for architectural decisions. All requirements below are additive relative to the existing importer — see "Domain contracts unchanged" for explicit regression guards.

---

## Capability: import-entry-and-menu

### REQ-01 — Entry point lives on Pagos, not Cargos

**Requirement:** The "Importar pagos" action MUST be reachable only from the Pagos screen (`/payment`) header actions. It MUST NOT appear on the Cargos screen (`/charge`).

**Invariants:**
- The button MUST be wrapped in a permission guard requiring `create.data_import`, matching the pattern of sibling actions on Pagos (e.g. "Registrar pago").
- Users without `create.data_import` MUST NOT see the button on Pagos.
- The `/import` route MUST remain gated by the `data_import` permission entity regardless of entry-point relocation.
- `ChargeListPage.tsx` MUST NOT retain the button, its click handler, or any now-unused navigation import/declaration.

### REQ-02 — Module visible in sidebar and ⌘K

**Requirement:** The DataImport module MUST appear in the sidebar and command palette (⌘K), under the Cobranza group, immediately after Pagos, for users holding the relevant permission.

**Invariants:**
- Visibility MUST follow the same permission-driven menu resolution as every other module (no hardcoded bypass).
- Users without the permission MUST NOT see the entry in sidebar or ⌘K.

---

## Capability: import-validation-feedback

### REQ-03 — General issues are marked structurally, not detected by text

**Requirement:** Each validation issue produced during `/import/validate` MUST carry an explicit structural marker of whether it is "general" (applies once regardless of row count) or "per-row" (specific to that row's data). This marker MUST be set at issue-creation time, never inferred from message text.

**Invariants:**
- The 7 catalog-reference issue types raised by `ReferenceResolver` (e.g. unregistered bank account, unregistered unit type) MUST be marked general.
- The 12 mandatory-field and the email cross-tenant-conflict issue types MUST NOT be marked general, even when their message text is identical across rows.
- No grouping logic MAY rely on string comparison of message text.

### REQ-04 — General issues collapse to one entry with a count; per-row issues stay flat

**Requirement:** When assembling the `/import/validate` response, all general issues that reference the same underlying catalog value MUST collapse into exactly ONE issue entry carrying an optional `count` of affected rows. Per-row issues (errors and the email conflict warning) MUST remain one entry per affected row.

**Invariants:**
- `count` on the issue contract MUST be OPTIONAL; when absent or `1`, the issue applies to a single row (no UI behavior change for ungrouped issues).
- The collapse MUST happen in `ImportRunner::validate()` response assembly — NOT inside `RowImporter`.
- The frontend "Fila" cell in `ValidateStep.tsx` MUST render "N filas" for a grouped issue's row indicator, and the plain row number for ungrouped issues.

#### Scenario: 1000 rows reference the same unregistered account

- GIVEN an import file with 1000 payment rows that all reference the same unregistered bank account name
- WHEN `POST /import/validate` runs
- THEN the response contains exactly ONE issue for that account, with `count: 1000`

#### Scenario: 3 rows are each missing a mandatory field

- GIVEN an import file where 3 different rows are each missing `fecha_pago`
- WHEN `POST /import/validate` runs
- THEN the response contains 3 separate issues, each identifying its own row number, and none carries a `count` greater than 1

#### Scenario: email cross-tenant warning stays per-row

- GIVEN 2 rows that each reference a different email already registered to another tenant
- WHEN `POST /import/validate` runs
- THEN the response contains 2 separate per-row warning entries (not collapsed), each tied to its own row number

### REQ-05 — Validate response contract preserved (regression guard)

**Requirement:** The shape of the `/import/validate` response MUST remain backward-compatible after grouping is introduced.

**Invariants:**
- The response MUST keep `total`, `units_to_create`, `residents_to_create`, `payments_to_register`, `blocked`, and `rows` (plus conditional `truncated`, `row_cap`, `preview_message`) as top-level keys.
- ERROR-type issues MUST remain in `rows` as a flat per-row list; the HTTP 422 response MUST keep its validation-error keys in the `rows.<n>.<col>` format.
- Errors and warnings MUST NOT coexist on the same row (existing behavior is unchanged by this work).

#### Scenario: 422 error keys keep their shape

- GIVEN an import file that fails backend validation (e.g. malformed structure triggering a 422)
- WHEN the request is rejected
- THEN every error key matches the pattern `rows.<row-number>.<column-name>`

### REQ-06 — RowImporter per-row contract unchanged (regression guard)

**Requirement:** `RowImporter::validate()`'s per-row return contract MUST NOT change as a result of this work.

**Invariants:**
- Grouping and counter logic MUST live in `ImportRunner`, not `RowImporter`.
- No existing `RowImporter::validate()` return key, type, or per-row error/warning shape may be removed or renamed.

### REQ-07 — Counters report distinct new entities, not valid-row count

**Requirement:** The `units_to_create`, `residents_to_create`, and `payments_to_register` counters returned by `/import/validate` MUST reflect the count of DISTINCT new entities implied by the valid rows, not the number of valid rows.

**Invariants:**
- `units_to_create` MUST count distinct unit identifiers among valid rows that do not already exist in the tenant.
- `residents_to_create` MUST count distinct resident identities among valid rows that do not already exist in the tenant.
- `payments_to_register` MAY continue to equal the valid-row count (each row is one payment), but MUST be computed explicitly rather than assumed identical to the other two counters.

#### Scenario: 1000 rows across 50 units, only 3 units are new

- GIVEN an import file with 1000 valid payment rows spanning 50 distinct units, of which 3 units do not yet exist in the tenant
- WHEN `POST /import/validate` runs
- THEN `units_to_create` equals `3`, NOT `1000`

### REQ-08 — Payment amount cap enforced on import

**Requirement:** A `monto` value in an import row that exceeds the platform's global money cap MUST be rejected as a blocking error, with a Spanish-language message.

**Invariants:**
- The cap MUST align with the existing `MoneyLimit` maximum used elsewhere in the payment flow (no separate, divergent limit for imports).
- The rejection MUST surface as a per-row error entry (not a general/collapsed issue), since the offending amount differs per row.

#### Scenario: monto exceeds the global cap

- GIVEN a row whose `monto` value is above `MoneyLimit`'s maximum
- WHEN `POST /import/validate` runs
- THEN the row is reported as a blocking error with a Spanish message stating the amount exceeds the allowed maximum

### REQ-09 — Validation messages and instructions are truthful and in Spanish

**Requirement:** All validator-facing text produced by the importer MUST be in Spanish and MUST accurately describe the importer's actual auto-create behavior.

**Invariants:**
- The LÉEME sheet's opening row MUST NOT claim the import does not create reference catalogs when it in fact auto-creates unit types and bank accounts; it MUST state which catalogs are auto-created.
- The LÉEME text MUST distinguish the THREE distinct behaviors and MUST NOT group them: (a) `tipo_unidad` / `cuenta_destino` → auto-created with a warning; (b) `torre` / `seccion` → unit imported without that field; (c) `estado_unidad` → tenant default applied (`'Activa'` created only when the tenant has none).
- The `tipo_unidad`, `nombre`, and `apellido_paterno` `string`-type validation rules MUST return Spanish-language messages instead of falling back to English defaults.
- **No validator-facing message may describe an outcome the code cannot produce.** Specifically, the `estado_unidad` warning MUST NOT claim the unit imports "sin estado": `unit.unit_status_id` is NOT NULL (`create_unit_table.php:24`) and `RowImporter.php:361-381` always resolves a status (tenant default, or a created `'Activa'`). It MUST describe the default-status fallback instead.

#### Scenario: non-string value in a string field

- GIVEN a row where `tipo_unidad` is entered as a numeric Excel value
- WHEN `POST /import/validate` runs
- THEN the reported message is in Spanish, not the English default "The tipo unidad field must be a string."

#### Scenario: unregistered unit status

- GIVEN a row whose `estado_unidad` names a status not registered in the tenant
- AND the tenant has at least one `unit_status`
- WHEN `POST /import/validate` runs
- THEN the warning states the cluster's default status will be used
- AND it does NOT claim the unit will be imported "sin estado"
- AND (on execute) the resulting unit carries the tenant's default `unit_status_id`, never null

### REQ-10 — Domain contracts unchanged (regression guard)

**Requirement:** This change MUST NOT alter `/import/execute` behavior, chunking topology, FIFO payment application, or any part of the payment-registration path.

**Invariants:**
- `PaymentService::register`, `ChargeStatusService` recompute, `receipt_number` minting, and credit-balance application MUST behave exactly as before this change for both `/import/validate` and `/import/execute`.
- No migration, schema change, or data change is introduced by this spec other than the `module_system.csv` menu row.

---

## Type Contract (Frontend)

> CORRECTED by the orchestrator gatekeeper. The first draft of this section invented a
> `type: 'error' | 'warning'` field and made `column` optional. Both are WRONG and would have
> caused a breaking rename. Verified against reality:
> `frontend/src/modules/import/schema.ts:16-21` declares `severity: IssueSeverity` and a
> REQUIRED `column: string`; `ValidateStep.tsx:22-23` filters on `r.severity`; the backend
> emits `'severity' => $severity` (`ReferenceResolver.php:313`). The existing field names are
> the contract. Changes here are ADDITIVE ONLY — renaming `severity` is forbidden.

```typescript
export type IssueSeverity = 'error' | 'warning'

export interface IIssueRow {
  row: number
  severity: IssueSeverity   // EXISTING — do NOT rename to `type`
  column: string            // EXISTING — required, NOT optional
  message: string
  general?: boolean         // NEW, optional — true only for collapsed catalog-reference issues
  count?: number            // NEW, optional — present only when general; absent/1 otherwise
}
```

No `any` allowed in this interface or in any component consuming it.

---

### REQ-11 — Truncated preview is disclosed to the admin

> ADDED after user approval, following an orchestrator discovery: the backend already computes
> the truncation signal but the frontend never renders it, so the reassurance in
> `ImportController.php:103` ("The admin isn't misled") is false in practice.

**Requirement:** When `/import/validate` truncates the preview at `validate_row_cap` rows, the import wizard MUST visibly tell the admin that only the first N rows were validated.

**Invariants:**
- The backend contract is UNCHANGED: it already returns `truncated: true`, `row_cap`, and `preview_message` (`ImportController.php:102-109`) only when truncation occurred. No backend change is required for this requirement.
- `IValidateResponse` MUST expose the three fields as OPTIONAL (`truncated?: boolean`, `row_cap?: number`, `preview_message?: string`) — they are absent on non-truncated responses.
- The notice MUST NOT be styled as a blocking error: truncation does not block execution, and `/import/execute` is deliberately NOT row-capped.
- The summary counters and issue list describe ONLY the previewed rows; the notice is what makes that scope honest.

#### Scenario: file larger than the cap

- GIVEN an import file with 5000 data rows and `validate_row_cap` = 1000
- WHEN `POST /import/validate` runs and returns `truncated: true`, `row_cap: 1000`
- THEN the wizard displays the backend's `preview_message` ("Se validaron las primeras 1000 filas del archivo. Importá el archivo completo para procesar el resto.")
- AND the admin is NOT shown an unqualified "Sin errores. El archivo está listo para importar." for a file whose remaining 4000 rows were never inspected

#### Scenario: file within the cap

- GIVEN an import file with 300 data rows
- WHEN `POST /import/validate` runs and omits `truncated`
- THEN no truncation notice is rendered

---

## Out-of-scope (confirmed, do NOT test or enforce)

- `PaymentService`, FIFO, earmark, money-cast behavior (verified unaffected).
- `ProcessImportChunkJob::isAutoCreateWarning()` text-based detection, stale docblock, hardcoded `'currency' => 'MXN'`.
- Streaming-XLSX rewrite, chunking topology, `/import/execute` behavior changes.
