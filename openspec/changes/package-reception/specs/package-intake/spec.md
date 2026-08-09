# Package Intake Specification

## Purpose

Defines the contract for registering one or many packages that arrive at the front desk.
Covers the `package` entity schema, custody fields captured at intake, the initial status
assignment, intake photo, bulk registration, and the automatic arrival notification to all
active residents of the target unit.

---

## Requirements

### Requirement: `package` entity schema

The `package` table MUST use `BaseModel::columns($table, true)` (adds `tenant_id` NOT NULL +
FK + index) and MUST carry the `BelongsToTenant` trait. The table closure MUST open with
`$table->comment('Paquetes recibidos en la portería del clúster')` as the first line.

Every own column (not defined by `BaseModel::columns()`) MUST carry a `->comment()`.

**Required columns:**

| Column | Type | Nullable | Purpose |
|---|---|---|---|
| `unit_id` | uuid FK → `unit` | NOT NULL | Target residential unit |
| `package_status_id` | uuid FK → `package_status` | NOT NULL | Current FSM state; service-owned |
| `recipient_user_id` | uuid | nullable | Nominal resident recipient (informational) |
| `recipient_name` | string | nullable | Free-text recipient when user unknown |
| `package_carrier_id` | uuid FK → `package_carrier` | nullable | Tenant catalog |
| `carrier_other` | string (max 100) | nullable | Free-text carrier when catalog absent |
| `package_type_id` | uuid FK → `package_type` | nullable | Tenant catalog |
| `tracking_number` | string (max 100) | nullable | Carrier tracking number |
| `description` | text | nullable | Brief package description |
| `storage_location` | string (max 100) | nullable | Where the package is stored (e.g. "Estante A3") |
| `pickup_code` | string | nullable | Unique per `(tenant_id, pickup_code)`; excluded from default serialization |
| `received_at` | datetime | NOT NULL | Intake timestamp; set by service |
| `received_by_user_id` | uuid | nullable | Guard who registered (no FK — pattern from `access_log.guard_user_id`) |
| `notified_at` | datetime | nullable | Timestamp of arrival notification dispatch |
| `reminded_at` | datetime | nullable | Timestamp of retention reminder dispatch |
| `picked_up_at` | datetime | nullable | Handover timestamp |
| `picked_up_by_user_id` | uuid | nullable | User who collected (no FK) |
| `pickup_proxy_name` | string (max 150) | nullable | Authorized proxy's name |
| `pickup_proxy_relationship` | string (max 100) | nullable | Proxy's relationship to resident |
| `incident_reason` | text | nullable | Reason for incident, return, or loss |

Indexes MUST be created on `unit_id` and `package_status_id`.

#### Scenario: Migration executes cleanly with correct tenant scoping

- GIVEN a clean database
- WHEN `php artisan migrate` is run
- THEN the `package` table is created with `tenant_id` NOT NULL
- AND indexes on `unit_id` and `package_status_id` are present
- AND every column has a comment in the database schema

---

### Requirement: Single-package intake via `PackageService::register`

`PackageService::register(array $data, User $guard)` MUST execute inside a database transaction and MUST:

1. Set `received_at = now()` and `received_by_user_id = $guard->id`.
2. Set `package_status_id = PackageStatus::READY` — packages are immediately ready for pickup
   upon registration (the `received` state is reserved; see `package-lifecycle` spec).
3. Generate `pickup_code` (format `XXXX-XXXX`, unique per tenant) if the tenant policy
   `package.pickup_code_enabled = true`. If false, `pickup_code` remains null.
4. Attach the intake photo to the `photo` media collection if a photo is provided.
5. If `package.auto_notify_on_arrival = true`, notify all active residents of the unit (active =
   `unit_user.end_date IS NULL`) via the `package.received` notification event and set
   `notified_at = now()`.

If any step fails, the transaction MUST roll back — no partial record is committed.

Exposed via `POST /api/package` (Orion resource `store`), delegating to `performStore`.

#### Scenario: Successful single intake

- GIVEN a guard with role `security` and a valid `unit_id` for the tenant
- WHEN `POST /api/package` is called with `{ unit_id, package_carrier_id, description, storage_location }`
- THEN HTTP 201 is returned
- AND the `package` row has `received_by_user_id = guard.id`, `received_at` set, `package_status_id = READY`

#### Scenario: Intake without optional fields succeeds

- GIVEN a guard submits a package with only `unit_id`
- WHEN the package is created
- THEN `recipient_user_id`, `carrier_other`, `tracking_number`, `description`, `storage_location` are all null
- AND HTTP 201 is returned

#### Scenario: Intake for a unit in another tenant is rejected

- GIVEN `unit_X` belongs to Tenant B
- WHEN a guard from Tenant A attempts to register a package for `unit_X`
- THEN HTTP 422 is returned (unit not found within tenant scope)
- AND no package row is created

#### Scenario: Intake photo is stored and linked

- GIVEN a guard submits a valid image file as `photo`
- WHEN the package is created
- THEN a media record exists in the `photo` collection linked to that package
- AND `notified_at` is set if the notification policy is enabled

---

### Requirement: Intake validates required and optional fields

`PackageCreateRequest` MUST use array-style validation rules. Required: `unit_id` (exists in
`unit` table within tenant scope). Optional, validated when present:

- `package_carrier_id`: must exist in `package_carrier` within tenant scope.
- `package_type_id`: must exist in `package_type` within tenant scope.
- `recipient_user_id`: valid UUID string (informational — no existence check needed).
- `tracking_number`: string, max 100.
- `description`: string, max 500.
- `storage_location`: string, max 100.
- `carrier_other`: string, max 100.
- `recipient_name`: string, max 150.
- `photo`: image file, max 10 MB (mimes: jpg, jpeg, png, webp).

#### Scenario: Missing unit_id returns 422

- GIVEN a POST request with no `unit_id`
- WHEN validation runs
- THEN HTTP 422 is returned with an error on the `unit_id` field

---

### Requirement: Bulk intake via `PackageService::batchRegister`

`PackageService::batchRegister(array $rows, User $guard)` MUST register multiple packages in a
single database transaction. If any row fails validation or persistence, the ENTIRE transaction
MUST roll back — no partial batch is committed.

Exposed via `POST /api/package/batch`. The request body MUST be an object with a `packages`
array; each element follows the same validation rules as a single create.

`PackageBatchRequest` MUST validate:
- `packages`: required array, min 1 item, max 50 items.
- Each `packages.*`: same rules as `PackageCreateRequest` (unit_id required, rest optional).

#### Scenario: Bulk intake with three valid packages

- GIVEN `POST /api/package/batch` is called with 3 valid package entries
- WHEN all entries pass validation
- THEN HTTP 201 is returned
- AND exactly 3 `package` rows are created in the database, all with `received_by_user_id = guard.id`

#### Scenario: Bulk intake rolls back on one invalid entry

- GIVEN a batch of 3 where the second entry has a `unit_id` that belongs to another tenant
- WHEN `POST /api/package/batch` is called
- THEN HTTP 422 is returned
- AND zero packages from the batch are persisted

---

### Requirement: Arrival notification to active residents

Notifications MUST be dispatched via `NotifiesResidents`. Active residents = users linked to
the unit via `unit_user` where `end_date IS NULL`.

Notification payload MUST include: carrier name (or `carrier_other`), `storage_location`
(if set), `received_at`, and `click_action: '/my-packages'`.

`notified_at` MUST be set on the package row after the notification is dispatched.

#### Scenario: Only active residents are notified

- GIVEN unit U has active residents A and B, and former resident C (`end_date` in the past)
- AND `package.auto_notify_on_arrival = true`
- WHEN a package is registered for unit U
- THEN users A and B each receive a `package.received` notification
- AND user C does NOT receive a notification

#### Scenario: No notification when policy is disabled

- GIVEN `package.auto_notify_on_arrival = false` for the tenant
- WHEN a package is registered
- THEN zero notification events are dispatched
- AND `package.notified_at` remains null

---

### Requirement: Role-based access for intake

`create.package` permission MUST be granted to roles `security` and `administrator`. Residents
and renters MUST NOT call intake endpoints.

#### Scenario: Resident cannot register a package

- GIVEN an authenticated user with role `resident`
- WHEN `POST /api/package` is called
- THEN HTTP 403 is returned

#### Scenario: Guard can register a package

- GIVEN an authenticated user with role `security`
- WHEN `POST /api/package` is called with valid data
- THEN HTTP 201 is returned
