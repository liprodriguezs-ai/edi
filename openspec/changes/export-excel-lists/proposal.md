# Proposal: Export to Excel on Business List Pages

> Formalizes the user-approved plan `/Users/rodriguez/.claude/plans/hola-mira-necesito-intenrar-glowing-dawn.md` (code-verified; canonical detail: model map, per-entity column lists, per-page wiring). Settled decisions — 15-entity scope, exclusions, `/customReport` pipeline reuse, the 3 endpoint fixes — are not up for re-litigation.

## Intent

Admins, treasurers, and board roles need filtered Excel exports of business lists for offline reporting and record-keeping. A generic export pipeline (`POST /customReport` + model `reportColumns()` + `useBaseList.onReport`) exists but serves only Collaborator/Package, and the shared endpoint is defective: authorization disabled and inverted, model resolution broken for every new target, body `filters` ignored (exports dump the full tenant dataset). Audit export adds a cross-tenant leak risk (central `audits` table, no `BelongsToTenant`).

## Scope

### In Scope

- Fix and harden `ReportController::customReport()`: Sanctum ability check `report.<snake_key>`; `_model` → FQCN + eager-loads map (replaces dead Audit mapping); apply JSON-body Orion-style `filters` as whitelisted `where` clauses; Audit tenant guard (`tenant_id` when tenancy initialized, else require wildcard token, else zero rows).
- `reportColumns()` on 15 models: Charge, ChargeBatch, Payment, Expense, BankAccount, Unit, Vehicle, UnitUser, AccessLog, Visitor, BlacklistEntry, Provider, CommunityProject, ProjectContribution, Audit — Spanish labels (user-facing headers); formatted money/dates/booleans/relations.
- "Descargar" button gated by `report.*` on the 15 list pages via `useBaseList.onReport`. Deviations: optional report props on `CatalogCrudModule` (Vehicle); `exportXlsx` in `useContributionsList` (ProjectContribution); replace AccessLog's page-only CSV button.
- `backend/tests/Feature/CustomReportTest.php`: authz 403/200, wildcard passes, Audit tenant isolation, filters narrow export.

### Out of Scope

- Catalogs, Announcements, Chat, Notifications, Company, CreditBalance, PaymentApplication exports.
- Full-text `search` and Charge `owner_ids` export parity (documented gaps); async/chunked exports.
- Package (own controller); Collaborator (regression-check only); seeder/permission changes (all 15 `report.*` rows already seeded and role-granted).

## Capabilities

### New Capabilities

- `custom-report-endpoint`: shared export endpoint contract — authorization, model resolution, body-filter application, tenant isolation.
- `list-excel-export`: per-entity export behavior — button gating, filtered dataset, Spanish headers, value formatting.

### Modified Capabilities

None (no main specs exist in `openspec/specs/`).

## Approach

Reuse, don't rebuild — four phases: (A) endpoint fixes in one core file; (B) `reportColumns()` per model, pattern `Collaborator.php:152`; (C) buttons per `CollaboratorList.tsx` pattern; (D) feature tests + pint + lint/build + manual QA via LAN IP origin. Tenant isolation is automatic for the other 14 models (`BelongsToTenant` + `initializeTenancy`); Audit gets the explicit guard mirroring `AuditController::buildFetchQuery`. Collections entities route through `@collections-expert` at apply; finish with `/review-stack` including tenancy.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `backend/core/src/Http/Controllers/ReportController.php` | Modified | authz, model map, filter translation, Audit guard |
| 15 models across `backend/Modules/{Collections,Treasury,Housing,Access,Providers,Projects,Audit}` | Modified | add `reportColumns()` |
| 15 list pages in `frontend/src/modules/**` + `shared/components/catalog/CatalogCrudModule.tsx` | Modified | export button wiring |
| `backend/tests/Feature/CustomReportTest.php` | New | endpoint authz/isolation/filter tests |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Audit cross-tenant leak | High without guard | Mandatory guard + isolation feature test |
| Permission fix breaks Collaborator export | Low | `report.collaborator` seeded/granted; regression QA |
| Memory on large exports (AccessLog) | Medium | Sync `->get()` + existing 4096M limit; move entity to existing async path if needed |
| Eager-load relation name mismatch | Medium | Verify each relation method name at apply (include key = method name) |
| Filter translation gaps | Medium | Whitelist fillable + id/timestamps, skip dotted fields; document residual gaps in PR |

## Rollback Plan

Pure code change — no migrations, seeders, or data changes. Revert the PR(s): buttons disappear, endpoint reverts to prior behavior. Prefer forward-fix for the authorization hardening; reverting it re-opens the unauthorized endpoint.

## Dependencies

- `report.*` permissions already seeded + role-granted (verified — no seeder work).
- Maatwebsite Excel + `ReportExport` proven in production (Collaborator).
- Downstream skills (pre-resolved paths, verified on disk): laravel-backend-architecture, react-frontend-architecture, stancl-tenancy at `/Users/rodriguez/code/residia/.agents/skills/{name}/SKILL.md`.

## Success Criteria

- [ ] All 15 lists download xlsx respecting active list filters, with Spanish headers and formatted money/dates/booleans/relations.
- [ ] 403 without `report.<key>` ability; button hidden without permission; wildcard token passes.
- [ ] Audit export contains only current-tenant rows (test-proven).
- [ ] Collaborator export regression passes.
- [ ] `php artisan test --compact --filter=CustomReport` green (host php, single process); `pint --dirty`, `pnpm lint`, `pnpm build` clean.
