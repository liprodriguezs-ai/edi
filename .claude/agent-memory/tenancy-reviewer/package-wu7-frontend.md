---
name: package-wu7-frontend
description: WU-7 Package frontend tenancy audit — isolation verdict, key patterns, two non-tenancy bugs worth remembering for future WUs
metadata:
  type: project
---

## Isolation verdict: SAFE

All WU-7 frontend files (schema, resource, store, pages, widget, config section) respect
tenant isolation correctly:

- **X-Tenant-Id NOT re-implemented**: every API call goes through the shared `apiClient`
  (axios instance from `@/shared/api/apiClient`). No custom headers added in package files.
- **Resident unit scope**: `GET /my-packages` enforced at backend (WU-2). Frontend just
  calls `PackageResource.myPackages()` and renders whatever the backend returns.
- **pickup_code exposure**: correctly ONLY shown in `MyPackagesPage` (own user's packages).
  Absent from `PackageListPage` table columns, `SelectStep` in the pickup modal, and from
  the `IPackage` type itself (lives on `IMyPackage` only).
- **Routes**: `/package` and `/my-packages` both use `PermissionRoute permissionEntity="package"`.
  Backend enforces staff exclusion (`create.package` holders get 403 on `/my-packages`).

## Key slug format (important for future reviews)

Config settings are stored with the full `package.*` slug prefix and the backend returns
them the same way. The frontend reads and writes `package.auto_notify_on_arrival`,
`package.requires_pickup_signature`, etc. This matches the canon pattern from
`CollectionConfigSection` (which uses `collections.*` full slugs). The tasks.md phrasing
"keys stripped of package. prefix" is misleading — the actual implementation mirrors
the collection settings pattern (full slug keys). The `PackagePickupModal` reading
`policyQuery.data?.['package.requires_pickup_signature']` is CORRECT.

## Non-tenancy bugs found in WU-7 (WARNING level)

### 1. Export button hits wrong endpoint
`PackageListPage.tsx:236` — `PackageResource.report(...)` calls the inherited `report()`
method from `createCrudApi` which posts to `POST /customReport` (legacy generic endpoint,
see `resourceApi.ts:107`). The package aging export endpoint is `GET /package/report/aging/export`
and was explicitly excluded from the legacy switch (WU-5: "we do NOT touch the legacy
customReport switch"). Export button fails silently at runtime.

Fix: add `agingExport(): Promise<Blob>` as a custom method in `PackageResource` calling
`apiClient.get('/package/report/aging/export', { responseType: 'blob' })`.

### 2. Wrong 7th config field
`PackageConfigSection.tsx:185-195` — Shows `package.max_days_in_custody` (unspecced) and
is missing `liability_disclaimer` (the actual 7th setting per spec). The orphan slug would
be created as a tenant-scoped Setting row if saved (tenant-isolated, no cross-tenant issue).
The liability disclaimer is simply absent from the UI.
