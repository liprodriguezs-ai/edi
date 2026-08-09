# Custom Report Endpoint Specification

## Purpose

Defines the contract of the shared backend export endpoint (`POST /customReport` → `ReportController::customReport()`) used by all "Descargar" (export to Excel) buttons across business list pages: authorization, model resolution, filter application, tenant isolation, and result ordering.

## Requirements

### Requirement: Sanctum Ability Authorization

The endpoint MUST require the caller to hold a Sanctum token ability of `report.<snake_key>` (where `<snake_key>` is the snake_case of the requested `_model`), OR the wildcard ability `*`. Requests without a matching ability MUST be rejected before any data is queried.

#### Scenario: Missing ability is rejected

- GIVEN an authenticated user whose token lacks `report.charge` and lacks `*`
- WHEN they POST `/customReport` with `_model=Charge`
- THEN the response is 401/403 and no report is generated

#### Scenario: Specific ability is authorized

- GIVEN an authenticated user whose token has `report.charge`
- WHEN they POST `/customReport` with `_model=Charge`
- THEN the request proceeds to model resolution and export

#### Scenario: Wildcard ability is authorized

- GIVEN an authenticated user whose token has the `*` ability
- WHEN they POST `/customReport` for any mapped `_model`
- THEN the request is authorized regardless of the specific `report.<key>` ability

### Requirement: Model Resolution via Explicit Map

The endpoint MUST resolve `_model` to a fully-qualified class name and its eager-load relations using an explicit map for the 15 new export targets (Charge, ChargeBatch, Payment, Expense, BankAccount, Unit, Vehicle, UnitUser, AccessLog, Visitor, BlacklistEntry, Provider, CommunityProject, ProjectContribution, Audit). Resolution for models not present in the map MUST fall back to the pre-existing string-concatenation convention (`Modules\{Model}\Models\{Model}`) used by legacy targets (User, Collaborator), unchanged.

#### Scenario: Mapped model resolves via the map

- GIVEN `_model=Charge`
- WHEN the endpoint resolves the model
- THEN it uses the map entry's FQCN and eager-load list, not the legacy string-concat fallback

#### Scenario: Legacy model resolution is preserved

- GIVEN `_model=Collaborator`
- WHEN the endpoint resolves the model
- THEN it uses the pre-existing string-concatenation resolution, producing the same class it resolved to before this change

### Requirement: Whitelisted Body-Filter Application

The endpoint MUST translate JSON-body `filters` entries (`{field, operator, value}`) into `where` clauses restricted to an allowlist of the resolved model's fillable attributes plus `id`, `created_at`, and `updated_at`. Supported operators are `=`, `!=`, `>`, `>=`, `<`, `<=`, `like`, `not like`, `in`, `not in`. Dotted (relation-path) fields MUST be skipped rather than applied or erroring.

#### Scenario: Filter narrows the export

- GIVEN a `filters` entry `{field: "charge_status_id", operator: "=", value: "<uuid>"}` in the POST body
- WHEN the export runs
- THEN only rows matching that where clause are included in the xlsx

#### Scenario: Dotted relation field is skipped

- GIVEN a `filters` entry with `field: "unit.tower_id"`
- WHEN filters are applied
- THEN this entry is skipped and does not raise an error or affect the query

#### Scenario: Non-whitelisted field is ignored

- GIVEN a `filters` entry whose field is not fillable and not one of `id`/`created_at`/`updated_at`
- WHEN filters are applied
- THEN that entry is ignored and the export proceeds using only whitelisted filters

### Requirement: Audit Cross-Tenant Isolation Guard

Because the `audit` table is central (no `tenant_id`-scoped model trait), exporting `_model=Audit` MUST apply an explicit tenant guard: scope to the current `tenant_id` when tenancy is initialized; when tenancy is not initialized, require the `*` wildcard ability, otherwise return zero rows.

#### Scenario: Tenant-scoped audit export

- GIVEN tenancy is initialized for tenant A
- WHEN a user with `report.audit` exports `_model=Audit`
- THEN only audit rows belonging to tenant A are included

#### Scenario: Cross-tenant export requires wildcard

- GIVEN tenancy is not initialized (e.g. super-admin context)
- WHEN a user with the `*` ability exports `_model=Audit`
- THEN rows from all tenants may be included

#### Scenario: No tenancy and no wildcard yields zero rows

- GIVEN tenancy is not initialized
- WHEN a user without the `*` ability exports `_model=Audit`
- THEN the export returns zero rows rather than leaking any tenant's data

### Requirement: Eager Loading from the Model Map

For each mapped model, the endpoint MUST eager-load the relations declared in that model's map entry before building report columns, so relation-derived columns do not trigger N+1 queries.

#### Scenario: Relation labels resolve without N+1

- GIVEN `_model=Payment` with map relations `unit, payer_user, payment_method, bank_account, payment_status`
- WHEN the export builds rows
- THEN those relations are eager-loaded in the initial query, not lazy-loaded per row

### Requirement: Deterministic Result Ordering

Exported rows MUST be ordered by `created_at` descending.

#### Scenario: Default ordering applied

- GIVEN any mapped model export with no explicit sort in the request
- WHEN the query executes
- THEN rows are ordered by `created_at` descending

## Out of Scope

- Full-text `search` (body `search.value`) is not translated into the export query.
- Charge's controller-level `owner_ids` filter does not apply to exports.
- Exports remain synchronous (`->get()`); no async/chunked export path is introduced by this change.
