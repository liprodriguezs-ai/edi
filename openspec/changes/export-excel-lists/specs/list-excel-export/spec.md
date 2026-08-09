# List Excel Export Specification

## Purpose

Defines the per-entity frontend export behavior on the 15 business list pages (Charge, ChargeBatch, Payment, Expense, BankAccount, Unit, Vehicle, UnitUser, AccessLog, Visitor, BlacklistEntry, Provider, CommunityProject, ProjectContribution, Audit): button gating, filtered dataset, header/value formatting, and the three documented frontend wiring deviations.

## Requirements

### Requirement: Permission-Gated Export Button

Each list page MUST show a "Descargar" export button only to users holding the `report.<key>` permission for that entity, and MUST hide it otherwise.

#### Scenario: Button visible with permission

- GIVEN a user whose role grants `report.charge`
- WHEN they view the Charge list page
- THEN the "Descargar" button is visible and enabled

#### Scenario: Button hidden without permission

- GIVEN a user whose role lacks `report.charge`
- WHEN they view the Charge list page
- THEN the "Descargar" button is not rendered

### Requirement: Export Honors Active List Filters

Triggering an export MUST send the list's current structured filters (as built by the page's existing `buildParams()`), so the downloaded file reflects only the filtered subset, not the full tenant dataset.

#### Scenario: Filtered export

- GIVEN a user has applied a status filter on the Payment list
- WHEN they click "Descargar"
- THEN the downloaded xlsx contains only payments matching the active filter

### Requirement: Spanish Column Headers

Exported xlsx files MUST use Spanish, user-facing column headers matching the entity's defined column list (e.g. "Unidad", "Monto esperado", "Estado"), not raw field/attribute names.

#### Scenario: Headers are in Spanish

- GIVEN any of the 15 entities is exported
- WHEN the xlsx is opened
- THEN the header row shows Spanish labels, not English attribute names

### Requirement: Value Formatting Rules

Exported values MUST be formatted for readability: money columns as decimal numbers (converted from minor units, not raw integers or Money objects); dates as `d/m/Y`; datetimes as `d/m/Y H:i`; booleans as `Sí`/`No`; relation-backed columns as their human-readable label, never the underlying UUID.

#### Scenario: Money as decimal

- GIVEN a Charge with `expected_amount` stored in minor units
- WHEN exported
- THEN the column shows a decimal number (e.g. `1500.00`), not cents or a Money object

#### Scenario: Date and datetime formatting

- GIVEN an AccessLog row with an `entered_at` timestamp
- WHEN exported
- THEN the column shows `d/m/Y H:i`; a date-only column elsewhere shows `d/m/Y`

#### Scenario: Boolean formatting

- GIVEN a Vehicle or BankAccount boolean column (e.g. "Activa")
- WHEN exported
- THEN the value shows `Sí` or `No`, not `true`/`false`/`1`/`0`

#### Scenario: Relation label instead of UUID

- GIVEN a Charge row linked to a `charge_status`
- WHEN exported
- THEN the "Estado" column shows the status's display name, not its UUID

### Requirement: Empty Result Feedback

WHEN an export request returns zero rows, the UI MUST show a toast informing the user, instead of silently downloading an empty/blank file or failing without feedback.

#### Scenario: Empty filtered export shows toast

- GIVEN a filter combination on the Visitor list matches no rows
- WHEN the user clicks "Descargar"
- THEN a toast notifies them no rows were found instead of downloading an empty file

### Requirement: Frontend Wiring Deviations

Three list pages deviate from the standard `useBaseList.onReport` wiring pattern and MUST implement equivalent export behavior through their own mechanism:

- **Vehicle** (rendered via `CatalogCrudModule`): the shared component MUST accept optional `reportModel`/`reportTitle` props that wire and permission-gate (`report.${permissionEntity}`) the export button.
- **ProjectContribution** (uses `useContributionsList`, a react-query hook, not `useBaseList`): the hook MUST expose an `exportXlsx` callback — named distinctly because `onReport` already denotes "report a contribution payment" in `ContributionsTable.tsx` — implementing equivalent filtered-export behavior.
- **AccessLog**: the pre-existing client-side `downloadCsv(entities)` button (which only exports the currently loaded page of rows) MUST be replaced by the server-side, filter-aware xlsx export button.

#### Scenario: Vehicle export via CatalogCrudModule

- GIVEN the Vehicle list page passes `reportModel="Vehicle"` and `reportTitle="vehiculos"` to `CatalogCrudModule`
- WHEN a user with `report.vehicle` views the page
- THEN the export button appears and downloads a filtered Vehicle xlsx

#### Scenario: ProjectContribution export via exportXlsx

- GIVEN a user with `report.project_contribution` on the ProjectContribution list
- WHEN they trigger the toolbar export action
- THEN `useContributionsList.exportXlsx` runs and downloads a filtered xlsx, without colliding with the existing `onReport` payment-reporting action

#### Scenario: AccessLog CSV button replaced

- GIVEN the AccessLog list page after this change
- WHEN a user exports data
- THEN they use the new server-side xlsx export (respecting filters across all matching rows), and the old page-only CSV button no longer exists

### Requirement: Collaborator Export Regression

The pre-existing Collaborator export MUST continue to function correctly after the shared endpoint's authorization, model-resolution, and filter fixes are applied.

#### Scenario: Collaborator export still works

- GIVEN a user with `report.collaborator`
- WHEN they export the Collaborator list, with or without active filters
- THEN the xlsx downloads successfully with correct, filtered data, matching pre-change behavior plus the new filter-honoring fix

## Out of Scope

- Per-user localization of column headers (headers are Spanish for all users; no locale switching).
- Full-text search and Charge `owner_ids` filter parity in exports (tracked as backend endpoint gaps, not a frontend requirement).
