# Package Policies Specification

## Purpose

Defines the cluster-configurable policies that govern package intake, pickup, and retention
behavior. Policies are stored in the generic `Setting` store (key/value, tenant-scoped) under
the `package.*` slug namespace. No dedicated `package_setting` table is created.

---

## Requirements

### Requirement: Seven policy slugs stored in `Setting`

The following slugs MUST be seeded for every new tenant (via `Setting::firstOrCreate` in the
`TenantCreated` event handler or listener):

| Slug | PHP type | Default | Description |
|---|---|---|---|
| `package.auto_notify_on_arrival` | bool | `true` | Notify active residents when a package arrives |
| `package.pickup_code_enabled` | bool | `true` | Generate a pickup code/QR for each package |
| `package.requires_pickup_signature` | bool | `false` | Require a signature file on handover |
| `package.requires_pickup_evidence` | bool | `false` | Require an evidence photo on handover |
| `package.reminder_after_days` | int | `3` | Days before a reminder is sent for uncollected packages |
| `package.return_after_days` | int | `10` | Days before a package is flagged/suggested for return |
| `package.liability_disclaimer` | text | *(default copy in Spanish, max 1000 chars)* | Disclaimer text shown to residents |

All seven slugs MUST be present in the tenant's `Setting` rows after `TenantCreated` fires.

#### Scenario: All policy slugs exist after tenant creation

- GIVEN a new tenant is created and `TenantCreated` fires
- WHEN the `Setting` table is queried for that tenant with `slug LIKE 'package.%'`
- THEN exactly 7 rows are returned
- AND each row matches the slug name and default value defined above

#### Scenario: Policy defaults are enforced at service level

- GIVEN a new tenant (all defaults apply)
- WHEN a package is registered
- THEN `PackageService` reads `package.auto_notify_on_arrival = true` and dispatches a notification
- AND `PackageService` reads `package.pickup_code_enabled = true` and generates a `pickup_code`

---

### Requirement: `GET /config/package-settings` returns all policy values

The endpoint MUST be registered in the `Configuration` module (following the mold of
`getCollectionSettings`). It MUST require the `read.package_config` permission.

Response shape:
```json
{
  "auto_notify_on_arrival": true,
  "pickup_code_enabled": true,
  "requires_pickup_signature": false,
  "requires_pickup_evidence": false,
  "reminder_after_days": 3,
  "return_after_days": 10,
  "liability_disclaimer": "..."
}
```

The response MUST be fully tenant-scoped: values from Tenant A MUST NOT appear in Tenant B's
response.

#### Scenario: Admin retrieves current policy settings

- GIVEN an administrator reads settings after a new tenant is created
- WHEN `GET /config/package-settings` is called
- THEN HTTP 200 is returned with all 7 keys at their default values

#### Scenario: Settings from other tenants are not returned

- GIVEN Tenant A has `auto_notify_on_arrival = false` and Tenant B has the default `true`
- WHEN an administrator of Tenant B calls `GET /config/package-settings`
- THEN `auto_notify_on_arrival` is `true` in the response (Tenant B's value)

---

### Requirement: `PUT /config/package-settings` persists policy updates

The endpoint MUST be registered in the `Configuration` module (mold: `updateCollectionSettings`).
It MUST require the `update.package_config` permission.

It MUST accept a partial update — only keys present in the request body are updated. Keys absent
from the body MUST NOT be reset to defaults.

Validation rules:
- `auto_notify_on_arrival`, `pickup_code_enabled`, `requires_pickup_signature`,
  `requires_pickup_evidence`: boolean.
- `reminder_after_days`, `return_after_days`: integer, min 1, max 365.
  `reminder_after_days` MUST be less than `return_after_days`.
- `liability_disclaimer`: string, max 1000 characters.

#### Scenario: Admin updates a boolean policy

- GIVEN `requires_pickup_signature = false` for a tenant
- WHEN `PUT /config/package-settings` is called with `{ requires_pickup_signature: true }`
- THEN HTTP 200 is returned
- AND `Setting` for `package.requires_pickup_signature` is now `true`
- AND `package.auto_notify_on_arrival` and other untouched slugs remain unchanged

#### Scenario: reminder_after_days >= return_after_days is rejected

- GIVEN a PUT request with `{ reminder_after_days: 10, return_after_days: 5 }`
- WHEN validation runs
- THEN HTTP 422 is returned with an error referencing the day-ordering constraint

#### Scenario: Non-admin cannot update policies

- GIVEN an authenticated user with role `security`
- WHEN `PUT /config/package-settings` is called
- THEN HTTP 403 is returned

---

### Requirement: `PackageService` reads policies from `Setting` at runtime

`PackageService` MUST retrieve each policy via `Setting::getValue('package.{slug}', $tenantId,
$default)` (or equivalent helper). It MUST NOT hardcode policy values in the service class.

The service MUST enforce:
- `pickup_code_enabled`: if false, `pickup_code` is not generated (remains null) and the
  QR pickup flow is unavailable.
- `requires_pickup_signature` + `requires_pickup_evidence`: enforced in `handover` — see
  `package-pickup` spec.
- `auto_notify_on_arrival`: controls notification dispatch in `register`.

#### Scenario: Disabling pickup_code_enabled prevents code generation

- GIVEN `package.pickup_code_enabled = false` for a tenant
- WHEN a package is registered
- THEN `package.pickup_code` is null after creation
- AND `POST /api/package/verify-code` returns 404 for any code (no codes exist)

---

### Requirement: `PackageConfigSection` frontend component

A frontend component `PackageConfigSection.tsx` MUST be created in
`frontend/src/modules/package/components/`. It MUST follow the structure of
`CollectionConfigSection.tsx` (read current settings on mount, allow admin to edit, submit via
`PUT /config/package-settings`).

The component MUST display all 7 policy fields with appropriate Spanish labels and input types
(toggle for booleans, number input for day counts, textarea for disclaimer). A save button MUST
be present and disabled while submitting.

The component MUST be integrated into the cluster configuration page or a dedicated
"Configuración de Paquetería" tab within the settings area.

#### Scenario: Admin sees current values on load

- GIVEN `requires_pickup_signature = true` is set for the tenant
- WHEN the admin navigates to the package settings section
- THEN the signature toggle is shown in the ON state

#### Scenario: Save shows success toast on update

- GIVEN the admin changes `reminder_after_days` from 3 to 5 and saves
- WHEN the PUT request succeeds
- THEN a success toast is displayed in Spanish
- AND the displayed value updates to 5 without a page reload
