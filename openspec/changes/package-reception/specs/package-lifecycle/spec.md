# Package Lifecycle Specification

## Purpose

Defines the `package_status` FSM, all valid state transitions, and the service-level operations
for returning a package, flagging an incident, and reassigning a misdelivered package to the
correct unit. Also clarifies that `notified_at` is a timestamp event, not an FSM state.

---

## Requirements

### Requirement: `package_status` FSM — allowed transitions

The FSM has five states with the following allowed transitions:

```
received ──(auto on register)──► ready ──(handover)──► picked_up  [terminal]
                                   │
                              (returnPackage)──► returned           [terminal]
                              (flagIncident)───► incident           [terminal]
```

`received` is reserved for future use (e.g., batch pre-registration before individual setup).
In the current implementation, `PackageService::register` MUST immediately transition status
from `received` to `ready` — a package MUST NOT remain in `received` state after a completed
registration.

Only `PackageService` is permitted to write `package_status_id`. Controllers MUST delegate
status transitions to the service; NEVER update `package_status_id` directly in a controller
or FormRequest.

`picked_up`, `returned`, and `incident` are terminal states. Any attempt to transition out of a
terminal state MUST be rejected with HTTP 422.

#### Scenario: Newly registered package lands in `ready`

- GIVEN `PackageService::register` is called with valid data
- WHEN the package is persisted
- THEN `package.package_status_id = PackageStatus::READY`
- AND `PackageStatus::RECEIVED` is NOT the persisted status

#### Scenario: Transition from `picked_up` is rejected

- GIVEN a package with status `picked_up`
- WHEN `PackageService::returnPackage` is called on it
- THEN an exception (translated to HTTP 422) is raised
- AND the package status remains `picked_up`

#### Scenario: Transition from `incident` is rejected

- GIVEN a package with status `incident`
- WHEN `PackageService::handover` is called for it
- THEN HTTP 422 is returned
- AND the package status remains `incident`

---

### Requirement: `notified_at` is a timestamp, not an FSM state

There is NO `notified` status in `package_status`. The fact that a package's arrival was
communicated to residents is recorded as `package.notified_at` (datetime, nullable).

`notified_at` MUST be set by `PackageService::register` after notification is dispatched.
Setting `notified_at` MUST NOT change `package_status_id`.

#### Scenario: notified_at is set without changing status

- GIVEN a package in status `ready` with `notified_at = null`
- WHEN `PackageService` dispatches the arrival notification
- THEN `package.notified_at` is updated to a non-null datetime
- AND `package.package_status_id` remains `PackageStatus::READY`

---

### Requirement: Return a package via `PackageService::returnPackage`

`PackageService::returnPackage(Package $package, string $reason)` MUST:

1. Verify current status is `ready`. If not, raise an exception (HTTP 422).
2. Set `package_status_id = PackageStatus::RETURNED`.
3. Set `incident_reason` to the provided `$reason` (reusing the existing column).
4. Persist and return the updated package.

Exposed via `POST /api/package/{id}/return` with body `{ reason: string (required, max 500) }`.

#### Scenario: Return a ready package

- GIVEN a package with status `ready`
- WHEN `POST /api/package/{id}/return` is called with `{ reason: 'No reclamado en plazo' }`
- THEN HTTP 200 is returned
- AND `package.package_status_id = PackageStatus::RETURNED`
- AND `package.incident_reason = 'No reclamado en plazo'`

#### Scenario: Return rejected if package is already picked up

- GIVEN a package with status `picked_up`
- WHEN `POST /api/package/{id}/return` is called
- THEN HTTP 422 is returned
- AND the package status is unchanged

---

### Requirement: Flag an incident via `PackageService::flagIncident`

`PackageService::flagIncident(Package $package, string $reason)` MUST:

1. Verify current status is `ready` (an incident cannot be flagged on an already-picked-up
   or returned package). If not, raise an exception (HTTP 422).
2. Set `package_status_id = PackageStatus::INCIDENT`.
3. Set `incident_reason` to the provided `$reason`.
4. Persist and return the updated package.

Exposed via `POST /api/package/{id}/incident` with body `{ reason: string (required, max 500) }`.

Use cases: package lost, package damaged, package disputed.

#### Scenario: Flag incident on a ready package

- GIVEN a package with status `ready`
- WHEN `POST /api/package/{id}/incident` is called with `{ reason: 'Paquete dañado al llegar' }`
- THEN HTTP 200 is returned
- AND `package.package_status_id = PackageStatus::INCIDENT`
- AND `package.incident_reason = 'Paquete dañado al llegar'`

#### Scenario: Flag incident rejected if already returned

- GIVEN a package with status `returned`
- WHEN `POST /api/package/{id}/incident` is called
- THEN HTTP 422 is returned

---

### Requirement: Reassign a misdelivered package via `PackageService::reassignUnit`

`PackageService::reassignUnit(Package $package, string $newUnitId)` MUST:

1. Verify `$newUnitId` belongs to the same tenant. If not, raise HTTP 422.
2. Verify the package is NOT in a terminal state (`picked_up`, `returned`, `incident`). A
   delivered package cannot be reassigned.
3. Update `package.unit_id = $newUnitId`.
4. Persist. The change MUST be captured by Laravel Auditing (GlobalObserver) — no additional
   audit column is needed.
5. If `package.auto_notify_on_arrival` policy is true, dispatch a `package.received`
   notification to the active residents of the NEW unit.
6. Return the updated package.

Exposed via `POST /api/package/{id}/reassign` with body `{ unit_id: uuid (required) }`.

Chain of custody is preserved: `received_by_user_id`, `received_at`, and `pickup_code` are NOT
reset. The package's history remains intact in the audit trail.

#### Scenario: Reassign package to correct unit

- GIVEN a package mistakenly assigned to unit U1 (status `ready`)
- WHEN `POST /api/package/{id}/reassign` is called with `{ unit_id: U2 }`
- THEN HTTP 200 is returned
- AND `package.unit_id = U2`
- AND `received_at` and `received_by_user_id` are unchanged
- AND an audit entry records the `unit_id` change from U1 to U2

#### Scenario: Reassign to a unit in a different tenant is rejected

- GIVEN unit U_other belongs to Tenant B
- WHEN a guard from Tenant A calls reassign with `unit_id: U_other`
- THEN HTTP 422 is returned
- AND the package's `unit_id` is unchanged

#### Scenario: Reassign a picked-up package is rejected

- GIVEN a package with status `picked_up`
- WHEN `POST /api/package/{id}/reassign` is called
- THEN HTTP 422 is returned

---

### Requirement: Permission gating for lifecycle operations

| Operation | Required permission |
|---|---|
| `return` | `update.package` |
| `incident` | `update.package` |
| `reassign` | `update.package` |

Only users with the `administrator` or `security` role receive `update.package`. Residents MUST
NOT be able to return, flag, or reassign packages.

#### Scenario: Resident cannot return a package

- GIVEN an authenticated user with role `resident`
- WHEN `POST /api/package/{id}/return` is called
- THEN HTTP 403 is returned
