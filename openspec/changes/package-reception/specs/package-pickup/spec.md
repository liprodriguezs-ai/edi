# Package Pickup Specification

## Purpose

Defines the contract for the pickup flow: pickup code generation (resident-only visibility),
guard-side code verification, multi-package handover in a single operation, proxy pickup,
optional signature/evidence enforcement, and per-package custody record keeping.

---

## Requirements

### Requirement: `pickup_code` is resident-only

Each package MUST have a `pickup_code` field that is:

- Unique per tenant: `unique(['tenant_id', 'pickup_code'])` constraint in the database.
- Format: `XXXX-XXXX` (9 characters: 4 uppercase alphanumeric characters, a dash, 4 uppercase
  alphanumeric characters). Generation MUST use a do-while loop checking for collisions, matching
  the pattern used by `AccessService::generateUniqueToken`.
- `$hidden` on the `Package` model — the field MUST be excluded from default serialization.
- Exposed **only** to the package's owning residents via the `GET /api/my-packages` endpoint.
  It MUST NOT appear in: `GET /api/package` (guard/admin list), `GET /api/package/{id}` responses
  for non-owning users, or `POST /api/package/verify-code` responses.

`pickup_code` is generated during `PackageService::register` when the policy
`package.pickup_code_enabled = true`. If `pickup_code_enabled = false`, `pickup_code` remains
null and QR-based pickup verification is unavailable.

#### Scenario: pickup_code is absent from guard index response

- GIVEN a guard with role `security` calls `GET /api/package`
- WHEN the JSON response is inspected
- THEN no object in `data` contains a `pickup_code` key
- AND no `pickup_code` value appears anywhere in the response body

#### Scenario: pickup_code is present in resident's my-packages response

- GIVEN an authenticated resident (role `resident`) who owns a pending package
- WHEN `GET /api/my-packages` is called
- THEN the package object in the response contains a non-null `pickup_code`
- AND the value matches the format `XXXX-XXXX`

#### Scenario: pickup_code uniqueness per tenant

- GIVEN 10 packages are registered for the same tenant
- WHEN all 10 `pickup_code` values are collected
- THEN all 10 values are unique (no duplicates within the tenant)

#### Scenario: Same pickup_code may exist in different tenants (isolation)

- GIVEN Tenant A has a package with `pickup_code = 'ABCD-1234'`
- WHEN Tenant B registers a package that happens to generate `pickup_code = 'ABCD-1234'`
- THEN both rows persist without a unique-constraint violation

---

### Requirement: `GET /api/my-packages` returns only the authenticated resident's packages

`GET /api/my-packages` MUST return all non-terminal packages for all units the authenticated
user is actively linked to (active `unit_user` rows where `end_date IS NULL`). Each package
object in this response MUST include the `pickup_code`.

Access to this endpoint MUST be gated by the `read.package` permission. Users with role
`security` or `administrator` MUST use the standard `GET /api/package` index instead.

#### Scenario: Resident sees packages for all their active units

- GIVEN resident R is active on units U1 and U2
- AND U1 has 2 pending packages, U2 has 1 pending package
- WHEN `GET /api/my-packages` is called as resident R
- THEN exactly 3 package objects are returned, all containing `pickup_code`

#### Scenario: Packages of other tenants are not visible

- GIVEN two tenants share the same user email but different tenant contexts
- WHEN `GET /api/my-packages` is called within Tenant A
- THEN only Tenant A packages for the resident's units are returned

---

### Requirement: `POST /api/package/verify-code` — guard scans resident QR

The endpoint accepts `{ pickup_code: string }`. `PackageService::verifyPickupCode(code)` MUST:

1. Look up the package by `pickup_code` within the current tenant scope.
2. If not found: return HTTP 404 `{ valid: false, reason: 'Código no encontrado' }`.
3. If found but status is `picked_up`, `returned`, or `incident`: return HTTP 422
   `{ valid: false, reason: 'El paquete no está disponible para retiro' }`.
4. If found and status is `ready`: return HTTP 200 with the list of **all** pending
   (`status = ready`) packages for the **same unit** — NOT just the matched package.
   This allows the guard to see every pending package for that resident's unit in one scan.

The response for a valid scan MUST follow the shape:
```json
{
  "valid": true,
  "unit": { "id": "...", "full_identifier": "..." },
  "packages": [
    {
      "id": "...",
      "description": "...",
      "storage_location": "...",
      "received_at": "...",
      "package_carrier": { "id": "...", "name": "..." }
    }
  ]
}
```

The response MUST NOT include `pickup_code` in any package object.

#### Scenario: Valid scan returns all pending packages for the unit

- GIVEN unit U has 3 packages with status `ready` and 1 with status `picked_up`
- AND one of the ready packages has `pickup_code = 'ABCD-1234'`
- WHEN `POST /api/package/verify-code` is called with `{ pickup_code: 'ABCD-1234' }`
- THEN HTTP 200 is returned with `valid: true` and `packages` containing exactly 3 items
- AND none of the package objects contain a `pickup_code` field

#### Scenario: Invalid code returns 404

- GIVEN no package with `pickup_code = 'ZZZZ-9999'` exists in the tenant
- WHEN `POST /api/package/verify-code` is called with that code
- THEN HTTP 404 is returned with `valid: false`

#### Scenario: Already-picked-up package returns 422

- GIVEN a package with status `picked_up` and `pickup_code = 'DONE-0001'`
- WHEN `POST /api/package/verify-code` is called with that code
- THEN HTTP 422 is returned with `valid: false`

---

### Requirement: `POST /api/package/handover` — multi-package handover in one operation

The endpoint accepts:
```json
{
  "package_ids": ["uuid1", "uuid2"],
  "pickup_proxy_name": "optional string",
  "pickup_proxy_relationship": "optional string",
  "pickup_evidence": "optional file (image/PDF)",
  "pickup_signature": "optional file (image)"
}
```

`PackageService::handover(array $packageIds, array $data, User $guard)` MUST:

1. Validate all `package_ids` belong to the same tenant and have status `ready`. If any ID is
   invalid or not `ready`, reject the entire operation with HTTP 422.
2. Enforce policies (see `package-policies` spec):
   - If `requires_pickup_signature = true` and `pickup_signature` is absent → reject HTTP 422.
   - If `requires_pickup_evidence = true` and `pickup_evidence` is absent → reject HTTP 422.
3. Within a **single database transaction**:
   - For each package: set `picked_up_at = now()`, `picked_up_by_user_id = guard.id`,
     `pickup_proxy_name`, `pickup_proxy_relationship`, `package_status_id = PackageStatus::PICKED_UP`.
   - Attach `pickup_evidence` and `pickup_signature` to each package's `pickup_evidence`
     media collection (one shared evidence file for the whole handover is acceptable; each
     package MUST have its own media record linking to the same upload).
4. Return HTTP 200 with the list of updated package objects.

The entire handover MUST be atomic: if any package fails to update, ALL changes are rolled back.

#### Scenario: Handover of two packages succeeds

- GIVEN packages P1 and P2 both have status `ready` for Tenant A
- AND policy `requires_pickup_signature = false` and `requires_pickup_evidence = false`
- WHEN `POST /api/package/handover` is called with `{ package_ids: [P1, P2] }`
- THEN HTTP 200 is returned
- AND P1 and P2 both have `package_status_id = PICKED_UP`
- AND both have `picked_up_at` and `picked_up_by_user_id` set

#### Scenario: Handover rejected when signature required but missing

- GIVEN policy `requires_pickup_signature = true` for the tenant
- AND packages P1 and P2 have status `ready`
- WHEN `POST /api/package/handover` is called WITHOUT a `pickup_signature` file
- THEN HTTP 422 is returned with an error referencing `pickup_signature`
- AND P1 and P2 remain in `ready` status

#### Scenario: Handover rolls back if one package is already picked up

- GIVEN P1 has status `ready` and P2 has status `picked_up`
- WHEN `POST /api/package/handover` is called with `{ package_ids: [P1, P2] }`
- THEN HTTP 422 is returned
- AND P1 remains in `ready` status (no partial update committed)

#### Scenario: Proxy fields recorded per package

- GIVEN packages P1 and P2 are handed over with `pickup_proxy_name = 'María López'` and
  `pickup_proxy_relationship = 'Esposa'`
- WHEN the handover succeeds
- THEN both P1 and P2 have `pickup_proxy_name = 'María López'` and
  `pickup_proxy_relationship = 'Esposa'` in the database

---

### Requirement: Permission gating for pickup endpoints

- `POST /api/package/verify-code`: accessible to users with `security` or `administrator` role.
  Residents MUST NOT access this endpoint.
- `POST /api/package/handover`: accessible to users with `security` or `administrator` role.
  Residents MUST NOT access this endpoint.
- `GET /api/my-packages`: accessible to users with `resident` or `renter` role. Guards and
  administrators MUST use `GET /api/package` instead.

#### Scenario: Resident cannot call verify-code

- GIVEN an authenticated user with role `resident`
- WHEN `POST /api/package/verify-code` is called
- THEN HTTP 403 is returned

#### Scenario: Guard cannot call my-packages

- GIVEN an authenticated user with role `security`
- WHEN `GET /api/my-packages` is called
- THEN HTTP 403 is returned (guards do not own packages; they use the main index)
