# Delinquency Restrictions — Specification

New capability. No prior `openspec/specs/delinquency-restrictions/` exists — this is a full spec, not a delta.

## Purpose

Configurable per-tenant blocking of visitor-access generation, package intake, and amenity reservation for units that are `OVERDUE` with balance at or above an administrator-set minimum, with audience-dependent messaging (explicit to the resident, subtle to the guard).

## Requirements

### Requirement: Delinquency criterion and master switch (R-DELQ-1)

A unit MUST be treated as delinquent only when `unit.charge_status_id === ChargeStatus::OVERDUE` AND its overdue balance is `>=` the configured `delinquency.min_overdue_amount`. `ChargeStatus::PARTIAL` (paid something but still within term) MUST NEVER be treated as delinquent. When `delinquency.restrictions_enabled` is `false`, no unit is ever restricted regardless of debt.

#### Scenario: Master switch off blocks nothing
- GIVEN `delinquency.restrictions_enabled = false` and a unit is `OVERDUE` far above threshold
- WHEN the unit attempts any guarded action
- THEN the action succeeds — no `DELINQUENT_RESTRICTED` response

#### Scenario: PARTIAL within term is never blocked
- GIVEN restrictions are enabled and a unit's `charge_status_id` is `PARTIAL`
- WHEN the unit attempts a guarded action
- THEN the action succeeds, even if the unpaid remainder exceeds `min_overdue_amount`

### Requirement: Overdue balance threshold (R-DELQ-2)

The overdue balance MUST be computed via `ChargeStatusService::effectiveExpected()` per overdue charge (never a fifth reimplementation of the outstanding formula). `min_overdue_amount = 0` MUST make any `OVERDUE` unit restricted.

#### Scenario: Below threshold is not blocked
- GIVEN `min_overdue_amount = 500000` (5,000 MXN) and a unit is `OVERDUE` with balance `499999`
- WHEN the unit attempts a guarded action
- THEN the action succeeds

#### Scenario: Zero threshold blocks any overdue unit
- GIVEN `min_overdue_amount = 0` and a unit is `OVERDUE` with balance `1`
- WHEN the unit attempts a guarded action
- THEN the action is blocked with `DELINQUENT_RESTRICTED`

### Requirement: Per-feature toggles (R-DELQ-3)

Each of `delinquency.block_visitor_access`, `delinquency.block_package_intake`, `delinquency.block_amenities` independently gates its own feature. A feature whose toggle is `false` MUST NOT block, even for a delinquent unit. Note: `block_amenities` gates **reservation creation only** — browsing the catalog, detail and availability stays open (R-DELQ-5).

#### Scenario: Feature toggle off exempts that feature only
- GIVEN a unit is delinquent and `block_amenities = false`, other toggles `true`
- WHEN the unit creates a reservation
- THEN the reservation succeeds; visitor-access generation and package registration remain blocked

### Requirement: Blocked actions (R-DELQ-4)

For a delinquent unit with its feature toggle on, the system MUST reject with HTTP 403 `code: DELINQUENT_RESTRICTED`: `ReservationService::create`, `AccessService::generateQr` (`POST /visitor`), `VisitorController::approve`/`reject` (walk-in), `PackageService::register`, `PackageController::batchRegister`.

#### Scenario: Delinquent unit blocked at or above threshold
- GIVEN a unit is `OVERDUE` with balance `>= min_overdue_amount` and its feature toggle is on
- WHEN the unit (or staff on its behalf) invokes one of the guarded actions
- THEN the response is HTTP 403 with `code: DELINQUENT_RESTRICTED`

### Requirement: Never-blocked actions (R-DELQ-5)

The following MUST always return 200 regardless of delinquency: `GET /access/my-qr`, `GET /access/my-log`, `PackageService::handover`/`verifyCode` of an already-registered package, and amenity catalog/detail/availability browsing.

#### Scenario: Own access and existing pickups are unaffected
- GIVEN a unit is delinquent above threshold
- WHEN it calls `my-qr`, `my-log`, or hands off/verifies a previously registered package
- THEN each call returns 200

#### Scenario: Amenity browsing is unaffected
- GIVEN a unit is delinquent above threshold
- WHEN it browses the amenity catalog, a detail, or availability
- THEN each call returns 200

### Requirement: Audience-dependent messaging and privacy (R-DELQ-6)

The 403 payload MUST differ by audience. Resident-facing blocks (`amenities`, `visitor_access`) MUST include the overdue amount and `delinquency.notice_text`. Guard-facing blocks (`package_intake`) MUST NOT include an amount, `notice_text`, or the words "debt"/"adeudo"/"moroso" — only the fixed message "Esta unidad no puede recibir paquetes".

#### Scenario: Resident sees explicit debt info
- GIVEN a resident's own unit is blocked from creating a reservation
- WHEN the 403 is returned
- THEN the payload includes the overdue amount and the configured notice text

#### Scenario: Guard sees no debt information
- GIVEN a guard attempts to register a package for a delinquent unit
- WHEN the 403 is returned
- THEN the payload contains no amount, no `notice_text`, and none of "debt"/"adeudo"/"moroso" — only "Esta unidad no puede recibir paquetes"

### Requirement: Guard-facing restricted-units listing (R-DELQ-7)

`GET /package/restricted-units` MUST return only unit ids (no amounts, statuses, or names), MUST be gated by `create.package`, and MUST NOT be served through the ungated `/selects` endpoint.

#### Scenario: Restricted-units payload and access
- GIVEN a caller holding `create.package` requests the endpoint
- WHEN restricted units exist
- THEN the response is a list of unit ids only, with no amounts, statuses, or resident/unit names

#### Scenario: Resident cannot call the guard endpoint
- GIVEN a resident (no `create.package`) requests `GET /package/restricted-units`
- WHEN the request is made
- THEN the response is HTTP 403

### Requirement: Settings storage and endpoints (R-DELQ-8)

The six `delinquency.*` settings live in the tenant-scoped `setting` table (no new tables/migrations). `min_overdue_amount` MUST use `Setting::TYPE_INTEGER` in minor units. `GET/PUT config/delinquency-settings` MUST be gated by the existing `read.configuration`/`update.configuration` abilities.

#### Scenario: Pesos convert to minor units
- GIVEN an admin submits `min_overdue_amount = 5000` pesos via the frontend
- WHEN it is persisted
- THEN the stored `setting` value is `500000` minor units

### Requirement: Immediate unblock on payment (R-DELQ-9)

A payment that drops a unit's overdue balance below `min_overdue_amount` (or clears `OVERDUE` status) MUST lift the restriction within the same database transaction that applies the payment — no separate recompute step.

#### Scenario: Payment lifts restriction atomically
- GIVEN a delinquent, restricted unit
- WHEN a payment is applied that drops its overdue balance below the threshold
- THEN a guarded action attempted immediately after the commit succeeds

### Requirement: Tenant isolation (R-DELQ-10)

`delinquency.*` settings and every guard check MUST be scoped to the acting tenant; tenant A's configuration or delinquent units MUST NOT affect tenant B.

#### Scenario: Cross-tenant settings do not leak
- GIVEN tenant A sets `restrictions_enabled = true` and tenant B leaves it `false`
- WHEN a delinquent unit in tenant B attempts a guarded action
- THEN it succeeds — tenant A's configuration has no effect

### Requirement: UI never hides or disables restricted actions (R-DELQ-11)

The "Reservar" and "Registrar visita" entry points MUST remain visible and enabled for a delinquent unit; the notice MUST render only when the action is initiated (inside the wizard/modal), never as a disabled control.

#### Scenario: Buttons stay actionable
- GIVEN a delinquent resident views the amenity reservation and visitor-access screens
- WHEN the screens render
- THEN "Reservar" and "Registrar visita" are visible and explicitly NOT `disabled`
- AND the restriction notice appears only after the resident initiates the action
