# Proposal: EDI Screening UI

## Intent

The screening lifecycle API (`edi-screening-application`) is delivered and tested (backend baseline 737), but nothing consumes it: screenings still happen in the falsifiable legacy UI, and the `Tamizajes` menu row is seeded hidden. This change delivers the React UI to apply, review, and manage EDI screenings — rendering server truth only, never computing results client-side.

## Scope

### In Scope
- `frontend/src/modules/screening/`: `ScreeningListPage` + `ScreeningDetailPage` on the shared scaffolding (`createEntityStore`, `createCrudApi`, `useBaseList`, `ResponsiveDataTable`).
- `ScreeningApplyPage` wizard: draft creation, server-provided item bank grouped by area, debounced idempotent autosave, score round-trip inserting the alternate block on 422 `ALTERNATE_ITEMS_REQUIRED`, server-rendered result, complete.
- `screeningDraftStore` (plain Zustand) + `useScreeningApplication()` — the only non-factory store: fields are server-determined, there is a mid-flow round-trip, and the result is server-owned (not an entity CRUD).
- `schema.ts` static header only; runtime `buildAnswerSchema(items)` from the returned bank.
- Detail: per-area semaphore, global result + message, findings, previous-test comparison, score-run timeline, confirmatory test capture (red + completed), rescore/invalidate actions.
- Registration (atomic): `App.tsx` routes under `PermissionRoute`, unhide `Tamizajes`, `iconMap.ts` icon, `$mapperSelects` + `CatalogType` pairs (`ScreeningStatus`, `ResultColor`, `ConfirmatoryTestResult`).
- Entry points: "Aplicar tamizaje" visit-row action + visit picker on the list.
- Vitest coverage for the schema builder, draft store, and result components.

### Out of Scope
- FUA PDF (own change), follow-up/referral, stimulation, reports, notifications.
- Standalone Pruebas Confirmatorias page (embedded in detail; its menu row stays hidden).
- Any backend behavior change beyond registration seeders/`$mapperSelects`.

## Capabilities

### New Capabilities
- `screening-ui`: React UI for applying and managing screenings — list, detail, apply wizard, server-truth rendering contract.

### Modified Capabilities
- `screening-application`: registration delta only — `Tamizajes` becomes visible and the deferred `$mapperSelects`/`CatalogType` entries land (handoff planned by REQ-SCR-013/D21).

## Approach

List/detail follow the standard module pattern. The apply wizard is the single custom surface: a server-driven form materialized from the item bank, a flush-queue autosave over the idempotent batch endpoint, and a score round-trip where the server adjudicates completeness, alternates, and results.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `frontend/src/modules/screening/` | New | 3 pages + components/hooks/services/stores/utils |
| `frontend/src/App.tsx`, `iconMap.ts`, `useCatalogs.ts` | Modified | Routes + icon + `CatalogType` |
| `backend` `SystemTableSeeder`, `AngularController` | Modified | Unhide menu; 3 mapper entries; adjust `MenuHiddenTest` |
| `frontend/src/modules/visit/` | Modified | "Aplicar tamizaje" row action |

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Client-side result derivation creeps in | Med | Options arrive colorless; REQ-SUI-006 bans it; tests assert it |
| Autosave races score / offline loss | Med | Flush barrier before score; idempotent replay; queue + backoff |
| Catalog pair mismatch (silent empty dropdown) | Med | Both sides in this change + wiring test |
| Review budget blowout | High | Chained PR slices per `tasks.md` |

## Rollback Plan

Revert the PR(s): remove module files/routes, re-hide the menu row, drop mapper/`CatalogType`/icon entries. No migrations, no data.

## Dependencies

- `edi-screening-application` delivered (endpoints + error codes stable).
- Baselines green: backend 737 / frontend 221.

## Success Criteria

- [ ] Full apply flow against the real API: draft → autosaved answers → 422 alternate → retry → scored → completed.
- [ ] Zero client-side result computation (review + tests).
- [ ] `tsc`, `eslint`, `vitest` (221 + new), `pnpm build` green; backend 737 green after seeder edits.

## Proposal question round

Interactive round unavailable (auto mode). Defaults encoded; correct before `sdd-apply`:

1. Wizard entry points = visit-row action + visit picker dialog on the list CTA. Default: yes.
2. Confirmatory tests embedded in `ScreeningDetail`; no standalone page/menu. Default: yes.
3. Rescore UI includes non-authoritative what-if with a version picker. Default: yes (minimal).
4. Score button enables on local completeness; server remains the gate. Default: yes.
5. New catalog pairs limited to `ScreeningStatus`/`ResultColor`/`ConfirmatoryTestResult`. Default: yes.
