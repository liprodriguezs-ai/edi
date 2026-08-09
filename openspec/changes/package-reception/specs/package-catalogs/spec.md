# Package Catalogs Specification

## Purpose

Defines the data contracts for the three catalog entities that underpin the package module:
`package_status` (central FSM), `package_carrier` (tenant-scoped label catalog), and
`package_type` (tenant-scoped label catalog). Catalog correctness is a prerequisite to every
other package-reception capability.

---

## Requirements

### Requirement: `package_status` is a CENTRAL FSM catalog

`package_status` MUST use `BaseModel::columns($table, false)` — no `tenant_id` column, no
`BelongsToTenant` trait. It MUST extend `CentralCatalogModel` (not `BaseModel` directly) so
that model caching is available. The model MUST define fixed UUID constants for every status
key; the code MUST reference those constants, never raw string keys.

Seeded once by `CatalogSeeder`. Status rows MUST NOT be editable by any tenant role. The five
required status keys are: `received`, `ready`, `picked_up`, `returned`, `incident`.

The `package` model MUST declare a `BelongsTo` relationship to `PackageStatus` named `status`.

#### Scenario: All five statuses exist after seeding

- GIVEN `CatalogSeeder` has run
- WHEN `PackageStatus::all()` is called
- THEN the result contains exactly the keys `received`, `ready`, `picked_up`, `returned`, and `incident`
- AND each row has a stable UUID matching the constant defined on the model class

#### Scenario: Status is not editable by tenant user

- GIVEN an authenticated user with the `administrator` role
- WHEN a PUT or PATCH request is sent to any `package_status` endpoint
- THEN HTTP 403 or 405 is returned
- AND the status row in the database is unchanged

#### Scenario: PackageStatus has no tenant_id column

- GIVEN the migration for `package_status` has run
- WHEN `DESCRIBE package_status` (or `\d package_status`) is inspected
- THEN there is no column named `tenant_id`

---

### Requirement: `package_carrier` is a TENANT-SCOPED label catalog

`package_carrier` MUST use `BaseModel::columns($table, true)` (adds `tenant_id` NOT NULL + FK +
index) and MUST use the `BelongsToTenant` trait. The unique constraint MUST be
`unique(['tenant_id', 'name'])` scoped to non-deleted rows. Soft-deletes are inherited from
`BaseModel`.

The model MUST expose: `name` (string), `is_active` (boolean, default true), `order` (integer,
default 0). No branching logic lives in this catalog — it is a free label.

`TenantCatalogSeeder` (triggered by the `TenantCreated` event) MUST seed the following carriers
for every new tenant: DHL, Estafeta, FedEx, UPS, Amazon, Mercado Libre, Correos de México, Otro.

A tenant admin MUST be able to create, rename, reorder, deactivate, and soft-delete carriers.
Deleting a carrier that is already linked to a `package` row MUST be blocked at the API level
(soft-delete is allowed; the FK remains valid because the row still exists).

#### Scenario: Carrier seeded for every new tenant

- GIVEN a new tenant is created and `TenantCreated` fires
- WHEN the tenant's `package_carrier` rows are queried
- THEN exactly the 8 default carriers are present with `is_active = true`

#### Scenario: Tenant isolation — carrier belongs to its tenant only

- GIVEN Tenant A and Tenant B each have their own carriers
- WHEN an authenticated user of Tenant A requests the carrier list
- THEN only Tenant A's carriers are returned
- AND Tenant B's carriers are absent from the response

#### Scenario: Duplicate carrier name within a tenant is rejected

- GIVEN Tenant A already has a carrier named "DHL"
- WHEN a POST request creates a second carrier named "DHL" for Tenant A
- THEN HTTP 422 is returned with a validation error on `name`

#### Scenario: Same carrier name allowed in different tenants

- GIVEN Tenant A and Tenant B both create a carrier named "DHL"
- WHEN both records are inserted
- THEN both succeed without a unique-constraint violation

---

### Requirement: `package_type` is a TENANT-SCOPED label catalog

`package_type` MUST follow the same tenancy and uniqueness pattern as `package_carrier`
(`BaseModel::columns($table, true)`, `BelongsToTenant`, `unique(['tenant_id', 'name'])`).

The model MUST expose: `name` (string), `is_active` (boolean, default true), `order` (integer,
default 0). No branching logic — free label.

`TenantCatalogSeeder` MUST seed the following types for every new tenant: Sobre, Caja,
Voluminoso, Perecedero, Frágil.

A tenant admin MUST be able to perform full CRUD (with soft deletes) on package types.

#### Scenario: Type seeded for every new tenant

- GIVEN a new tenant is created and `TenantCreated` fires
- WHEN the tenant's `package_type` rows are queried
- THEN the 5 default types are present with `is_active = true`

#### Scenario: Type deletion blocked when package references it

- GIVEN a `package` row references `package_type_id = X` for Tenant A
- WHEN a DELETE request is sent for type X
- THEN HTTP 422 is returned
- AND type X still exists in the database (soft-delete would succeed; hard-delete MUST NOT proceed if FK references exist)

---

### Requirement: `PackageCarrierController` and `PackageTypeController` enforce admin permissions

Both catalog controllers MUST gate create/update/delete operations behind the
`create.package_carrier` / `update.package_carrier` / `delete.package_carrier` (and equivalent
for `package_type`) permissions. Read (`read.package_carrier` / `read.package_type`) is
accessible to any authenticated tenant user.

#### Scenario: Security guard can read carriers but not create

- GIVEN an authenticated user with role `security`
- WHEN GET `/api/package_carrier` is called
- THEN HTTP 200 is returned with the carrier list
- WHEN POST `/api/package_carrier` is called
- THEN HTTP 403 is returned

---

### Requirement: All catalog migrations include `->comment()` on every column

Every column in `package_status`, `package_carrier`, and `package_type` migrations (columns not
already defined by `BaseModel::columns()`) MUST carry a `->comment('...')` describing its
purpose. The table closure MUST open with `$table->comment('...')` on the first line.

#### Scenario: Migration executes cleanly

- GIVEN a clean database
- WHEN `php artisan migrate` is run
- THEN `package_status`, `package_carrier`, and `package_type` tables are created with zero errors
