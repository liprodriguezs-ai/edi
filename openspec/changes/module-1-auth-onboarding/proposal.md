# Proposal — Module 1: Auth/Onboarding

## Intent
Bootstrap the Kivo7 backend with the authentication and onboarding foundation:
flexible user credentials (email/password optional for data-only residents),
multi-cluster tenant selection, per-cluster user profiles, and the 10 new residential roles.

## Scope
- `user` table: email/password nullable + partial unique index + new auth fields
- `user_profile` table: tenant-scoped PII per user per cluster (new `UserProfile` module)
- Kivo7 roles: 10 new roles added to the Spatie catalog (admin.super reused as-is)
- Multi-cluster tenant selection: `X-Tenant-Id` header with membership validation + `last_active_tenant_id` persistence
- `AuthController`: `login()` and `me()` return `tenants` (all) + `tenant` (active)
- Integration tests: Auth flows + UserProfile isolation + multi-cluster security

## Approach
Mirror existing Housing module conventions for the tenant-scoped pattern.
Add a dedicated `UserProfile` nwidart module for separation of concerns.
Use a Postgres partial unique index for the "null emails don't conflict" requirement.
Extend `InitializeTenancyFromUser` with secure multi-cluster resolution.
