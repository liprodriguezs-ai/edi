---
name: architecture-observations
description: Architecture patterns, conventions, and structural observations for Kivo7 React frontend
metadata:
  type: project
---

## Module structure (June 2026)
- 37 modules in src/modules/. Most follow 5-layer pattern (schema, store, resource, hook, List/Form/Modal/Row).
- New "Categoría C" modules: access, announcement, chat, communityProject, configuration, expense, payment (all added in recent cycle).
- CatalogCrudModule is a shared generic CRUD for catalog pages — well architected.
- 136 uses of createEntityStore/createCrudApi — factory pattern is well adopted.

## Query key conventions
- TanStack Query keys are NOT centralized (no QUERY_KEY constants file). Keys are string literals per file.
- Seen `QUERY_KEY` constant in 2 files — not consistent. Most files use inline arrays like `['chat', 'messages', channelId]`.
- Inconsistency: some invalidations use partial keys `['charges']` while queries use more specific `['charges', 'batch', id]`.

## Store selector patterns
- Some modules use `useShallow` correctly for object selectors (TenantList, SubscriptionPlanList, expense, unit, chargeConcept, CatalogCrudModule).
- Some List pages destructure 3 fields individually (e.g., UnitListPage with useShallow + separate isDeleting selector) — correct.
- ChatPage uses raw useQuery/useMutation directly without store layer — intentional pattern for real-time pages.

## Chat module: intentional deviation from standard CRUD pattern
- ChatPage does NOT use createEntityStore/useBaseList/useBaseCrud.
- Instead: useQuery for channels/messages, 5 useMutation for chat actions (hide/unhide/edit/react/direct).
- 10-second polling for messages. This is acceptable for a chat feature.
- BUT: no useCallback on inline handlers (onHide, onUnhide, onReact, onEdit, onDirectMessage) — these create new functions every render.

## Chat channel scope selector — SingleTargetSelector unification (2026-06-30)
- `ChannelTargetSection.tsx` deleted. `ChannelFormModal` now uses 2x `SingleTargetSelector` (read + write blocks).
- Isolation via module-level constants `READ_TARGET_NAMES` / `WRITE_TARGET_NAMES` (zero field overlap). This is the correct pattern.
- `names` prop on `SingleTargetSelector` (type `SingleTargetNames`) maps logical keys to actual RHF field names.
- `DEFAULT_SINGLE_TARGET_NAMES` points to cobranza fields — no `names` prop needed in ChargeBatchFormPage/ChargeRuleFormPage.
- `board` NOT in `SINGLE_TARGET_VISIBLE_TYPES`. Chat passes `CHANNEL_AVAILABLE_TARGET_TYPES` which includes it.
- KNOWN FRAGILITY: `onUnitSelect`/`onUnitIdsChange` callbacks in ChannelFormModal hardcode field names independently of `names.unitId`/`names.unitIds` — must be kept in sync manually.
- KNOWN STALE DOC: `buildChannelTargetSelector` JSDoc says "by_type still wraps single id" — now it receives string[] directly.
- KNOWN PRE-EXISTING: `useCatalogs` fires before `if (!isOpen) return null` in ChannelFormModal (4 catalogs eager-load always).

## Error boundaries
- StrictMode enabled in main.tsx.
- RouteErrorBoundary wraps all routes in App.tsx and DashboardLayout.tsx — good coverage.
- No per-module error boundaries (acceptable given route-level coverage).

## Firebase messaging (useFirebaseMessaging.ts)
- 11 console.log violations — this is a known ESLint error but low priority for prod (debug code).
- Dynamic import for Firebase SDK — correctly deferred.

## Testing
- schema.test.ts files exist for access and communityProject modules.
- No component or hook tests found for the new Categoría C features.
- Zero coverage on critical flows: QR scan (ScanQrModal), payment registration, chat send.

## Tableros contables (dashboards/) — June 2026
- 5 dashboards added: resultados, cobranza, tesorería, gastos, proyectos.
- Correct patterns: boneyard-js Skeleton, toast from @/shared/hooks/useToast, formatMinorUnits, no raw axios (all via createCrudApi custom methods), lazy-loaded in App.tsx, PermissionRoute gating.
- Each dashboard has a resource-level test file (__tests__) covering URL contract and payload shape — good coverage baseline.
- dashboards use read-only useQuery (no store/useBaseList) — intentional, correct for read-only analytics endpoints.
- KpiTile is imported cross-module from treasurerPanel — should be promoted to shared/components.
- AreaTrendChart generates gradientId from seriesLabel; same-page uniqueness is safe now (labels differ and dashboards render in separate routes), but the technique is fragile if names ever collide. Use useId() instead.
- DonutChart has an unimplemented centerLabel prop (API surface lie).
- LineBarComboChart has dead-code chartConfig module-level constant.
- Chart wrappers pass aria-label but ChartContainer has no role="img", making the label ineffective for AT.
