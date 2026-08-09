---
name: anti-pattern-violations
description: Active anti-pattern violations found in Kivo7 frontend as of June 2026 audit (updated through Firebase/notification-bell cycle; merged 2026-07-20 with the configuration/chat/subscription-multi-item cycles that had been logged in a stray backend/ copy)
metadata:
  type: project
---

## Anti-patterns per CLAUDE.md §4 found in codebase (June 2026)

### animate-pulse (Tailwind) instead of boneyard-js Skeleton
- `src/shared/components/molecules/TableLoadingState.tsx:25` — uses `animate-pulse` in showSkeletons branch
- The default (non-skeleton) branch correctly uses Loader2 spinner

### shadcn Skeleton import instead of boneyard-js
- 7 files import from `@/components/ui/skeleton` directly:
  - `src/modules/collaborator/components/CollaboratorProfileHeader.tsx`
  - `src/modules/collaborator/components/CollaboratorDataTab.tsx`
  - `src/modules/collaborator/me/MyDataCard.tsx`
  - `src/modules/collaborator/me/MyDocumentsCard.tsx`
  - `src/modules/collaborator/me/DossierStatusCard.tsx`
  - `src/modules/role/RoleDetail.tsx`
  - `src/modules/role/components/RolePermissionsEditor.tsx`
- CORRECT pattern: `import { Skeleton } from 'boneyard-js/react'` with loading prop

### Direct apiClient usage (not via createCrudApi/resourceApi)
- `src/modules/notification/NotificationListPage.tsx` — 3 direct apiClient calls
- `src/modules/tenant/components/TenantSystemsModal.tsx` — apiClient.get
- `src/modules/subscriptionPlan/components/PlanSystemsModal.tsx` — apiClient.get
- `src/modules/unit/components/UserCombobox.tsx` — apiClient.post

### new Date() for date formatting (not displayFormatDate)
- `src/modules/user/SubscriptionPage.tsx:57` — `new Date(iso).toLocaleDateString('es-MX', ...)`
- `src/modules/collaborator/components/CollaboratorProfileHeader.tsx:11-12` — tenure calc (acceptable: arithmetic, not display formatting)
- ChargeBatchFormModal, TreasurerHome, ReportPreviewDialog use `new Date()` for initial default date values (to get current date) — these are acceptable uses

### Type escape hatches (as never, as unknown)
- 8+ uses of `as never` in modules (CommunityProjectDetailPage, ChargeBatchDetailPage, CollaboratorForm, etc.)
- `CatalogCrudModule.tsx:141` — `zodResolver(schema as never) as never` (generic constraint issue)
- `src/modules/audit/AuditForm.tsx` — multiple `as unknown`, `as string`, `as number`

### Local interface outside schema.ts
- `src/modules/notification/NotificationListPage.tsx:11` — `INotificationUser` defined inline (no schema.ts for this module)

### UI text NOT in Spanish
- None found in recent modules. Pattern is well followed.

### Dead code: chartConfig in LineBarComboChart (June 2026)
- `src/shared/components/charts/LineBarComboChart.tsx:36-45` — module-level `chartConfig` const is defined but only used on Bar/Line fill/stroke. The `resolvedConfig` (per-render) is what's passed to ChartContainer for theming. Both happen to have the same color values so no visual bug, but `chartConfig` is dead code. Fix: delete `chartConfig`, use `resolvedConfig` colors on Bar/Line (i.e., `fill="var(--color-bar)"` style via ChartContainer's scoped CSS vars, or inline `resolvedConfig.bar.color`).

### Declared but unused prop: centerLabel in DonutChart (June 2026)
- `src/shared/components/charts/DonutChart.tsx:27` — `centerLabel?: string` is declared in the interface and destructured in props but never rendered. Either implement the center text (Recharts `<Label>` inside `<Pie>`) or remove the prop.

### KpiTile imported from wrong module (June 2026)
- 5 `dashboards/*/` files import `KpiTile` from `@/modules/treasurerPanel/components/KpiTile`. That component belongs to a different business module. Should be moved to `shared/components/` or `components/ui/` and re-exported from there. See architecture-observations for context.

### Inline local interfaces outside schema.ts (June 2026 — acceptable pattern for adapter types)
- `CobranzaDashboard.tsx:107` — `ITopDebtorRow` is defined inline (adapter to satisfy `ResponsiveDataTable<T extends IEntity>`).
- `ProyectosDashboard.tsx:108` — `IProyectoTableRow` same pattern.
- These are NOT schema types; they are local adapter structs. The `[key: string]: unknown` index signature required by `IEntity` makes a generic adapter necessary. This is acceptable, but consider adding a shared `buildEntityRow<T>(partial: T, extra?: Record<string, unknown>): T & IEntity` utility to `shared/interfaces/Entity.ts` to eliminate the boilerplate.

### Missing role="img" on ChartContainer (June 2026)
- `src/components/ui/chart.tsx:66` — `ChartContainer` renders a plain `<div>`. All chart wrappers pass `aria-label` via `ariaLabel` prop, which spreads onto the div. Without `role="img"` (or `role="figure"`), assistive technology ignores the `aria-label`. Chart components themselves should pass `role="img"` alongside `ariaLabel`, or `ChartContainer` should add it by default when a label is present.

**Why:** These violations persist across cycles. Tracking them here helps the next review cycle focus on the remaining ones rather than re-discovering them.

---

## Billing v2 full audit (2026-07-01)

### CRITICAL: Race condition isMounted vs cancelled flag in PlanPriceMatrix
- `src/modules/billingPackage/components/PlanPriceMatrix.tsx:229-251` — fetch effect uses `isMounted.current` for async guards, but this only protects unmount, not re-trigger (plan change). Stale response can overwrite fresh one. Fix: local `cancelled` flag + cleanup return.

### WARNING: `renderRow` in TenantList doesn't pass isDeleting/isSaving/isLoading to TenantTableRow
- `src/modules/tenant/TenantList.tsx:164-173` — TenantTableRow expects `isDeleting`/`isSaving`/`isLoading` but they're not passed from renderRow. Buttons stay enabled during delete. Fix: add the 3 loading props.

### WARNING: ChangeBillingPlanModal uses Label (shadcn raw) instead of FieldLabel (field system)
- `src/modules/user/components/ChangeBillingPlanModal.tsx:158` — `<Label>` from `@/components/ui/label` instead of `<FieldLabel>` from field system. Misses built-in required/aria-required support.

### WARNING: ChangeBillingPlanModal fetches catalog with useState+useEffect instead of useQuery
- `src/modules/user/components/ChangeBillingPlanModal.tsx:45-73` — Server state without TQ cache. Every modal open triggers fresh fetch. Should use `useQuery({ queryKey: ['billing-catalog'], staleTime: 5_min })`.

### WARNING: TenantBillingPanel uses `as string` type cast instead of type predicate
- `src/modules/tenant/components/billing/TenantBillingPanel.tsx:72` — `.map((e) => e.module_name as string)`. Fix: type predicate in the preceding filter `(e): e is IBillingEntitlement & { module_name: string } =>`.

### WARNING: TenantList `const options = []` without type annotation
- `src/modules/tenant/TenantList.tsx:90` — implicit `never[]` risk. Fix: `const options: ActionOption[] = []`.

### What was done well in billing v2
- Zero `any` across all 12 files reviewed
- Minor units (centavos↔pesos) correctly documented and converted throughout (Math.round, /100, *100)
- useBillingQuoter: debounce + quoteTokenRef race prevention + URL hydration via ref
- All modals conditionally mounted (lazy loading correct)
- BillingPackageFormModal uses `form={FORM_ID}` HTML native pattern (valid alternative to submitRef)
- parseMutation uses safeParse — prevents webhook-timing mutation shape from causing error
- boneyard Skeleton, sileo toast, displayFormatDate all used correctly

---

## Billing Fase 1 refactor audit (2026-06-30)

### CONFIRMED CRITICAL: `.uuid` access with type-escape on catalog items
- `src/modules/tenant/TenantWizardPage.tsx:36-37` — `(role as Record<string, unknown>)?.uuid` — IEntity exposes `.id`, not `.uuid`. Cast always returns `undefined`, making `accountOwnerRoleId` always undefined. Step 2 (UserForm) never enables. Fix: `roles.find(...).id`.

### Eager useCatalogs at page mount (not step gate)
- `src/modules/tenant/TenantWizardPage.tsx:30-32` — `useCatalogs({ ROLE })` fires on page mount (step 0), but catalog is only consumed at step 1. Fix: `{ enabled: currentStep === 1 }`.

### quoteError silently ignored in WizardPlanSelector
- `src/modules/tenant/components/WizardPlanSelector.tsx:42-47` — `quoteError` and `retryQuote` from `useBillingQuoter` not destructured. Failed quote shows $0.00 with no error state. Potential incorrect payment registration.

### FieldLabel htmlFor / id missing in mini add-package form
- `src/modules/tenant/components/billing/ManualPaymentSection.tsx:329-392` — Paquete, Período, Monto (MXN) labels have no `htmlFor`; their controls have no `id`. WCAG 1.3.1 gap.

### What's done well in this cycle
- WizardPlanSelector catalog load is genuinely lazy (only mounts when provisionMode is courtesy/manual_paid). Correctly documented in JSDoc.
- activeEntitlementKeys useMemo before early returns in TenantBillingPanel.
- useFieldArray with correct pesos↔centavos conversion in ManualPaymentSection.
- skipPermissionsModal declaration merge in apiClient — clean opt-out pattern.
- subscriptionInactiveHandled module-level flag prevents duplicate 403 handling during polling.

---

## Required-fields form convention — enforcement rules (June 2026)

Active review rules. Flag these in every data-entry form audit.
Spec: `docs/superpowers/specs/2026-06-30-formularios-datos-requeridos-design.md`.

### (a) `<FieldLabel>` sin `required` en campo Zod-requerido
- **Qué buscar:** `<FieldLabel htmlFor="...">` sin prop `required` en un campo cuyo tipo Zod es requerido (`.min(1)`, `.positive()`, o tipo base sin `.optional()` / `.nullable()`).
- **Por qué:** el asterisco visual no se renderiza y el usuario no sabe que el campo es obligatorio.
- **Exenciones:** `login-form`, `signup-form`, `forgot-password-form`, `reset-password-form`, `accept-invitation-form`.

### (b) Formulario de datos sin `<RequiredFieldsLegend />` al inicio
- **Qué buscar:** cualquier formulario de ingreso interno cuyo cuerpo no empiece con `<RequiredFieldsLegend />` (antes del primer `<FieldSet>`).
- **Import correcto:** `import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'`
- **Por qué:** sin la leyenda, los formularios con campos requeridos carecen del indicador global de convención.
- **Exenciones:** mismas que (a).

### Rollout audit results (2026-06-30) — convention applied to ~33 forms
- **Required/optional mapping is ACCURATE.** Audited 14 forms vs their Zod schemas; zero mismatches. Conditional-required handled via dynamic prop `required={booleanExpr}` (the codebase idiom): VisitorFormModal `required={selectedQrType === QR_TYPE.EVENT}`, CommunityProject `required={!isMandatory}`, ChangeOwnerDialog `.refine()` field marked `required`. This is the CORRECT pattern for `superRefine`-conditional fields — prefer it over static `required`.
- **RECURRING: legend placement split ~50/50.** Skill §2.8 canonical = INSIDE `<FieldGroup>` as first child (inherits `gap-7`). ~12 forms do this; ~11 place it BEFORE `<FieldGroup>` on a `<form>` with no `space-y` → legend renders flush against the first field (cramped). Cramped group: AccessNowPage, EntryRequestModal, VisitorFormModal, AnnouncementComposeModal, StartPeriodModal, ChannelFormModal, CommunityProjectListPage, ContributeModal, ExpenseFormModal, InviteResidentModal, ChargeConceptFormModal. In future form reviews enforce: legend INSIDE FieldGroup as first child.
- **Payment sheets hand-write the asterisk** — `RegisterPaymentSheet.tsx:501` + `SubmitPaymentSheet.tsx:223`: `Comprobante {!isCash && <span className="text-destructive">*</span>}`. Violates §2.8 "nunca escribirlo a mano". The `receipt` File is conditionally-required and lives in `useState` (not a Zod field), so the conditional `*` is justified — but the fix is `<FieldLabel required={!isCash}>` (same idiom as VisitorFormModal). Not yet fixed.
- `CatalogCrudModule` gates the legend with `hasRequired = fields.some(f => f.required)` — correct. Hand-edited forms render it unconditionally (fine; all audited forms have ≥1 required field).
- `components/ui/field.tsx` UNCHANGED. Asterisk reused from built-in `FieldLabel`: `data-[required=true]:after:text-destructive after:content-["_*"]` + sets `aria-required`. Legend's `text-destructive` matches.

---

## Access Control module audit (June 2026)

### Orion relation include name vs interface key mismatch (CRITICAL — renders blank)
- `VisitorListPage.tsx:130` — include string `{ relation: 'createdByUser' }` is camelCase. Backend `Visitor.php:57` defines `public function createdByUser()` — so the include is CORRECT. BUT the frontend interface `IVisitor.created_by_user` (schema.ts:34) reads the result as snake_case. Since Orion uses the method name as the JSON key, the response will have key `createdByUser`, but the code reads `visitor.created_by_user?.name` (VisitorListPage.tsx:223) — this ALWAYS renders blank.
- `AccessLogListPage.tsx:61-62` — includes `{ relation: 'entryType' }` and `{ relation: 'guardUser' }`. Backend methods are `entryType()` and `guardUser()` (AccessLog.php:64,79). JSON keys will be `entryType` and `guardUser`. But interface reads `log.entry_type` and `log.guard_user` (schema.ts:49,51; AccessLogListPage.tsx:113,116) — both always blank.
- `BlacklistListPage.tsx:46` — include `{ relation: 'blacklistType' }`. Backend method is `blacklistType()` (BlacklistEntry.php:34). JSON key will be `blacklistType`. Interface reads `entry.blacklist_type` (schema.ts:69; BlacklistListPage.tsx:34 render) — always blank.
- Pattern: interface properties use snake_case but backend relation methods are camelCase — fix is either rename backend methods to snake_case OR rename interface properties to camelCase.

### VisitorResource typed as Record<string,unknown> for create/update (type safety loss)
- `services/AccessResource.ts:5` — `createCrudApi<IVisitor, Record<string, unknown>, ...>`. The form type param is `Record<string,unknown>` instead of `VisitorFormValues`. This means `create()` and `update()` lose compile-time validation of the payload shape.

### Type cast workarounds in SelectItem renders
- `AccessNowPage.tsx:229,249` and `EntryRequestModal.tsx:112` — `(item as { name?: string }).name` to render catalog items. The `useCatalogs` hook already returns typed catalog items with `name: string`. The cast indicates the catalog item type returned by `useCatalogs` doesn't expose `name` in its public type, but this cast pattern is a symptom of the type system not being tight. Same issue seen in other modules.

### refresh() not wrapped in useCallback
- `VisitorListPage.tsx:138` — `const refresh = () => onSearch({ ...paramsSearch })` is a plain arrow (new ref every render). Passed to `actionMutation.onSuccess` via closure — acceptable for mutation callbacks since it's not a prop. Medium priority.
- `AccessLogListPage.tsx:72` — same pattern, but `refresh` is passed directly as `onSaved={refresh}` prop to `CorrectionModal` — this IS a prop passed to a child, so it re-creates a new function ref every render.

### handleApprove/handleReject not wrapped in useCallback
- `VisitorListPage.tsx:155-156` — both handlers are plain arrows passed as props to `VisitorActions` (rendered inside `renderRow` and `renderActions` callbacks). Since rows are not memoized TableRow components, this is a medium issue, not critical.

### useMyAccessLog missing isError state
- `hooks/useMyAccessLog.ts:7` — only exposes `{ logs, lastPage, isLoading }`. The `isError` and `error` from `useQuery` are not surfaced. `MyAccessLogPage.tsx` has no error state rendering — if the API fails, the page shows nothing with no user feedback.

### ManualEntryModal/EntryRequestModal/VisitorFormModal — double handleSubmit invocation
- Pattern across 3 modals: `<form onSubmit={handleSubmit(...)}` AND footer button `onClick={handleSubmit(...)}`. This means the footer button calls handleSubmit directly (bypassing the form's submit event), while the form's onSubmit handler is also wired. The footer button is `type="button"` so it doesn't trigger form submit. This is technically not a double-fire bug, but it's redundant and confusing — the form's onSubmit is unreachable without a submit button inside the form. Should use submitRef pattern (react-frontend-architecture skill §2.9) or put a hidden `type="submit"` button inside the form.

### StatusPill imported cross-module (3 files)
- `AccessLogListPage.tsx:9`, `MyAccessLogPage.tsx:6`, `VisitorListPage.tsx:8` — all import `StatusPill` from `@/modules/treasurerPanel/components/StatusPill`. This is the same KpiTile cross-module import pattern flagged in previous cycles. StatusPill should be promoted to `shared/components/`.

### INamed local private interface in schema.ts
- `schema.ts:4-7` — `INamed { id: string; name: string }` is defined as a private local interface (not exported). It's a commonly recurring inline type across modules (same as `CatalogItem` found in chargeConcept and payment). Should be in `shared/interfaces/catalog.types.ts`.

---

## Announcement module — edit capability (June 2026)

### double handleSubmit still present
- `AnnouncementComposeModal.tsx:255,332` — `<form onSubmit={handleSubmit(...)}>` AND footer `<Button onClick={handleSubmit(...)}>`. No `type="submit"` inside the form; `form.onSubmit` is dead code. Same pattern as Access module modals. Not fixed here. Pattern is widespread.

### expires_at date format risk (WARNING — to verify)
- `AnnouncementComposeModal.tsx:125` — `expires_at` hydrated as-is from `IAnnouncement.expires_at`. If backend returns ISO datetime (`2026-07-15T00:00:00Z`), `<input type="date">` displays blank and update silently sends `null`. Fix: `.slice(0, 10)` on hydration. Needs backend format verification.

### StatusPill cross-module import — RESOLVED
- Moved to `shared/components/atoms/StatusPill` in plan 047. AnnouncementListPage correctly uses the new path.

---

## Access module batch — plan 047 (June 2026)

### RESOLVED: include name mismatch (camelCase vs snake_case)
- All three flagged mismatches (`entryType`→`access_entry_type`, `guardUser`→`guard_user`, `blacklistType`→`blacklist_type`, `motive`→`visitor_motive`) are now fixed in this batch. Backend relation methods were renamed to snake_case; frontend interface keys and include strings now match.

### RESOLVED: VisitorResource Record<string,unknown> type
- `services/AccessResource.ts` now uses `VisitorFormValues` for create/update type params.

### RESOLVED: StatusPill cross-module import
- Moved to `shared/components/atoms/StatusPill`. All 4 callers updated.

### RESOLVED: useMyAccessLog missing isError
- `hooks/useMyAccessLog.ts` now exposes `isError` and `error`. `MyAccessLogPage.tsx` renders the error state.

### RESOLVED: type cast workarounds in SelectItem renders
- `(item as { name?: string }).name` casts removed in `AccessNowPage.tsx`. Correct `item.name` now used.

### NEW: MyQrPage missing staleTime — refetch thrash risk
- `MyQrPage.tsx:113-117` — `useQuery` has no `staleTime`. Combined with `refetchOnWindowFocus: true`, every tab focus triggers a background refetch even if the data is still fresh. Since the QR rotates every 60s, set `staleTime: 50_000` to prevent unnecessary refetches.

### NEW: qr_expires_at not cleared when switching QR type to SINGLE_ENTRY
- `VisitorFormModal.tsx` — when user selects SINGLE_ENTRY after previously entering a datetime on EVENT/TIME_BASED, the `qr_expires_at` field is hidden (via `needsExpiry`) but the value remains in form state and is passed as `undefined` only if `needsExpiryAtSubmit` is false. Actually the submit logic handles this correctly (`expiresAtIso` becomes `undefined` for SINGLE_ENTRY). The superRefine also does not fire for SINGLE_ENTRY. So this is NOT a bug — submit is safe. But the leftover form value is invisible to the user. Medium cosmetic issue, not a correctness bug.

### NEW: datetime-local timezone conversion — intentional local-to-UTC
- `VisitorFormModal.tsx:87` — `new Date(values.qr_expires_at).toISOString()` converts a `datetime-local` string (local wall-clock, no tz) to UTC ISO. This is the standard approach for datetime-local inputs. The timezone interpretation is the browser's local timezone. This is correct per project conventions — the backend stores in UTC and the user sees local times via `displayFormatDate`.

### RESOLVED: ScanQrModal — double-submit on rapid camera decodes
- Previously: camera decode callback called `mutateRef.current(value)` unconditionally per frame, no isPending guard.
- FIXED (June 2026 crash-fix batch): `isPendingRef` (useRef) is set true in `mutationFn`, reset in onSuccess/onError. Decode callback now early-returns on `value.length === 0 || isPendingRef.current`. Closes the duplicate-entry gap on rapid camera decodes.

### ScanQrModal crash-fix verification (June 2026) — FIX IS SOUND
- Original crash: `new Html5Qrcode(id)` throws (constructor, html5-qrcode 2.3.8 esm line 88-89) when the Radix Dialog portal hasn't mounted the `#scan-qr-camera-region` div on the tick the effect runs → RouteErrorBoundary crashed the whole Access section.
- Fix: rAF poll for `document.getElementById(CAMERA_REGION_ID)` + outer try/catch around BOTH `new Html5Qrcode` AND `scanner.start(...).catch(...)`.
- Verified: `start()` (html5-qrcode 2.3.8) has a SYNCHRONOUS pre-Promise section (lines 109-146) that can throw outside the returned Promise — incl. `element.clientWidth` deref if the div vanishes between construct and start. The component's outer try/catch covers it; the `.catch()` alone would NOT. Covered.
- rAF loop is bounded: the `#scan-qr-camera-region` div renders under `cameraSupported` inside `<Dialog open={isOpen}>` (no forceMount), and the effect runs under `isOpen && cameraSupported` — identical gate → element always eventually mounts; cleanup sets `cancelled=true` + `cancelAnimationFrame`.
- Camera permission-denied / no-device degrades gracefully: `.start()` rejects → `.catch` sets `cameraError`, manual input + HID reader stay fully usable. On reject, scannerRef is left non-null but `stopCamera()` on close handles it (isScanning false → clear() in try/catch). No leak/crash.
- stopCamera race-safe: captures `scanner` local + nulls ref BEFORE awaiting stop(), so a re-run won't clobber a new instance.
- No `any`; refs stable (mutateRef synced in effect, not during render). Reset-on-open uses the render-phase "adjust state on prop change" pattern correctly (not an effect).

---

## Collections/Payment modules audit (June 2026)

### `as never` type escape
- `src/modules/chargeBatch/ChargeBatchDetailPage.tsx:43` — `ChargeBatchResource.show(id, { include: 'concept,status' } as never)` because `show()` signature doesn't accept extra params. Fix: add custom method or adjust ResourceApi typing.
- Same file, lines 113–114, 319: `(batch as IChargeBatch)` cast — `useQuery` returns `IChargeBatch | undefined` and the conditional guard on line 93 (`if (isLoading || !batch)`) narrows it already. The cast is redundant and indicates inference was broken by the `as never` above.

### ChargeConceptResource dead-code type hack
- `src/modules/chargeConcept/services/ChargeConceptResource.ts:6-7` — `as ReturnType<...> & { __formValues?: ChargeConceptFormValues }` is a dead phantom property used nowhere. Pure dead code acting as a type annotation workaround. Should be removed.

### Local interfaces outside schema.ts (component-level)
- `src/modules/chargeConcept/ChargeConceptFormModal.tsx:34` — `CatalogItem { id, name }` is defined inline; same interface exists in multiple files across the codebase.
- `src/modules/payment/components/RegisterPaymentSheet.tsx:50` — another `CatalogItem { id, name }` defined inline.
- Both are trivially shared via a `shared/interfaces/catalog.types.ts` file.

### Orion include relation names — camelCase mismatch risk
- `ChargeListPage.tsx:155` — `{ relation: 'chargeStatus' }` is camelCase. Backend skill §2.4 says relation method names are EXACTLY what Orion uses. If the backend method is named `charge_status()`, the include will 422. Must match backend method name exactly.
- Same for `paymentStatus`, `paymentMethod`, `payerUser`, `amountMode` in PaymentListPage and ChargeConceptListPage.

### MONTHS array duplication — DRY violation
- `src/modules/chargeBatch/ChargeBatchListPage.tsx:24` defines its own `MONTHS` array (short form, 1-indexed with leading empty).
- `src/modules/payment/StatementPage.tsx:18` defines an identical `MONTHS` array.
- `src/modules/chargeBatch/constants.ts` already exports `MONTH_NAMES` (long form, 0-indexed) for the form.
- Three different representations of months across 3 files. Should be consolidated in `shared/utils/dateHelper.ts`.

### RegisterPaymentSheet.tsx — file over 500 lines
- `src/modules/payment/components/RegisterPaymentSheet.tsx`: 642 lines — violates ESLint `max-lines: 500` rule.

### StatementPage.tsx and TreasurerHome.tsx — inline response interfaces in wrong file
- `src/modules/payment/StatementPage.tsx:27-43` — `StatementRow`, `StatementData`, `BankDataInfo` interfaces defined inline in the page component.
- Some of these duplicate or shadow `StatementResponse` which IS in schema.ts. `StatementData` at line 34 is a looser version of `StatementResponse` (optional `totals`) — this divergence causes two casts (`statement as StatementData`) in the file (lines 325, 388).
- `TreasurerHome.tsx:45-48` — `DelinquencyData` is defined locally when `DelinquencyResponse` in schema.ts already has `units_with_debt` and `total_outstanding_minor_units`.

### DelinquencyPage — `new Date()` for arithmetic (acceptable) but inline type adapter is present
- `src/modules/payment/DelinquencyPage.tsx:33` — `type DelinquencyRow = DelinquencyUnit & IEntity` is the inline adapter pattern. Same observation as dashboards (acceptable but verbose). The comment in the file correctly explains why.

---

## Firebase + notification-bell audit (June 2026 — Cambios 1+2)

### FCM globals never cleared between logout/login (WARNING)
- `useFirebaseMessaging.ts:90-91,114` — `globalInitPromise` and `globalListenerSet` are module-level and never reset. In SPA logout-without-reload, token from user A stays registered for user B; the `onMessage` listener stays active; no re-init occurs. The `onMessage` unsubscribe return value is also discarded (line 118). Fix: export `resetFcmGlobals()` and call it from authService on logout, or document explicitly that page reload on logout is required.

### fallbackBeep AudioContext leak (WARNING)
- `notificationSound.ts:59-81` — every call to `fallbackBeep()` creates a new `AudioContext` that is never closed. Browsers warn ~6 concurrent contexts. If `/sounds/notification.wav` is missing (404 at build), every push creates a leaked context. Fix: singleton AudioContext OR call `ctx.close()` on `oscillator.ended` event.

### Firebase SDK version mismatch between SW and main app (WARNING)
- `public/firebase-messaging-sw.js:2-3` — SW pins compat SDK `10.12.0` via gstatic importScripts; main app npm dep is `firebase@12.11.x`. Low-risk today (FCM compat protocol stable), but undocumented divergence. At minimum, add a comment explaining the intentional pin.

### apiClient direct in notification-bell.tsx (anti-pattern violation)
- `notification-bell.tsx:37-53` — `fetchUnreadCount` and `fetchRecent` call `apiClient.post(...)` directly in the component file. Same violation as NotificationListPage, TenantSystemsModal, etc. Fix: extract to `shared/notifications/NotificationUserResource.ts` using `createCrudApi` + `customMethods`.

### scope="col" missing in NotificationPreferencesPage table
- `NotificationPreferencesPage.tsx:86,88-90` — The category × channel preferences table has no `scope="col"` on headers. Missed by the recent responsive-table accessibility fix (commit 6119078). WCAG 1.3.1.

### user-scalable=no in index.html (SUGGESTION — WCAG 1.4.4)
- `index.html:5` — `maximum-scale=1.0, user-scalable=no` prevents low-vision users from zooming. Remove both attrs; PWA `display:standalone` already gives the app-like feel.

### What is done well in this area (for future review reference)
- `navigateRef` equality guard `if (navigateRef === navigate)` correctly prevents stale ref on unmount.
- Security: only public Firebase web keys forwarded to SW via query params. VAPID not in URL. Tests verify (`firebaseEnv.test.ts:31-44`).
- PWA + FCM SW scope isolation correct: Workbox at `/`, FCM at `/firebase-cloud-messaging-push-scope`. `navigateFallbackDenylist` + `globIgnores` prevent collision.
- Unread badge: incremental TQ cache (+1 push, -1 read, 0 mark-all) with `refetchOnWindowFocus: true` reconciliation. No polling.
- `toast.info` uses sileo correctly. `displayFormatDate` used in bell. No `any` anywhere in these files.

---

## Configuration / chat / subscription cycles (recovered 2026-07-20 from stray `backend/` copy)

### Triplicated Pick<> type across configuration module (observed 2026-06-27)

`NotificationsConfigSection.tsx`, `useConfiguration.ts`, and `ConfigurationResource.ts`
all independently define the same `Pick<INotificationEventConfig, 'key' | 'is_active' | 'channel_email' | 'channel_push' | 'channel_whatsapp' | 'board_role_ids'>` type.

Rule: export a named type alias from the resource file where the interface lives; import it in the hook and in the component Props.

### `toast.error` receives object, not string (pre-existing in useConfiguration.ts)

`useConfiguration.ts:32` — the shared `onError` helper passes `formatApiErrorMessage(error)` (which returns `{ title, description? }`) directly to `toast.error()`. The canonical pattern (see `CollectionConfigSection.tsx:59-60`) is to destructure `{ title }` first. All mutations share this buggy helper, including `notificationsMutation`.

### "Coming soon" UI controls left functionally interactive

`NotificationsConfigSection.tsx:130` — WhatsApp toggle rendered with `opacity-50` label but `disabled` is conditional (not always-true). When event is active and user has update permission, the toggle is fully interactive and saves `channel_whatsapp: true`. Product intent was "visually muted = non-functional." Fix: `disabled={true}` on coming-soon controls unconditionally.

### `CARD` constant duplicated across siblings

`NotificationsConfigSection.tsx:9`, `NotificationTemplatesSection.tsx:10`, `CollectionConfigSection.tsx:21` all define the same `const CARD = '...'` string. Should be extracted to a shared constant in the configuration module.

### No fetch error state in configuration sections (systemic)

All config sections show an empty/null state when their query fails — no error banner distinguishes a failed fetch from a legitimately empty response. This is systemic across the configuration module, not unique to the notifications section.

### ManualUserPicker: labels state initialized empty, edit mode shows UUID slice (observed 2026-06-28)

`ManualUserPicker.tsx` — `labels` state is always `{}` on mount and only populates via `onSelect`. When editing a channel with existing `target_user_ids`, chips display `id.slice(0, 8)` instead of user names. Fix: accept `initialLabels?: Record<string, string>` prop, derive from `channel?.members`.

### `(item as { name?: string })` cast pattern for catalog items (observed 2026-06-28)

`ChannelFormModal.tsx:144`, `ChannelTargetSection.tsx:55` — `useCatalogs` return type likely lacks `name` on `IEntity[]`. Items are cast to access `.name`. Proper fix: define `ICatalogItem extends IEntity { name: string }` and use it as the return type of `useCatalogs`.

### RadioGroupItem inside `<label>` without explicit id/htmlFor (observed 2026-06-28)

`ChannelFormModal.tsx:188-191`, `ChannelTargetSection.tsx:89-90` — RadioGroupItem wrapped in `<label>` without `id`/`htmlFor`. Inconsistent with CheckboxList in the same file which uses explicit `htmlFor`. Radix renders `button[role=radio]`; screen reader support for implicit `<label>`+`<button>` association is inconsistent. Use explicit `id`/`htmlFor` pattern.

### shadcn Slider: aria-label/aria-valuetext/id put on `<Slider>` never reach the thumb (observed 2026-06-30)

`components/ui/slider.tsx` spreads `{...props}` onto `SliderPrimitive.Root` (a span), NOT onto `SliderPrimitive.Thumb` (the `role="slider"` element). Radix does not forward Root aria to the Thumb. So `aria-label`, `aria-valuetext`, and `id` set on `<Slider>` give the focusable thumb NO accessible name and NO value text — and `<label htmlFor>` pointing at the Root span does nothing for AT. First seen in `checkoutSubscription/components/PlanBuilder.tsx` (PlanBuilder UnitsSlider). Any slider in this project has the same gap. Real fix requires forwarding labelling props to the Thumb in the atom (conflicts with "NUNCA modificar components/ui/") — flag for team decision; an adjacent `aria-live` region is only a partial mitigation.

### toast.error(formatApiErrorMessage(error)) IS correct (clarification 2026-06-28)

`useToast.ts` signature is `error(titleOrOpts: string | SileoOptions, description?: string)`. `formatApiErrorMessage` returns `{ title, description? }` which satisfies `SileoOptions`. Passing the full object is CORRECT and shows both title and description. The anti-pattern-violations note about `useConfiguration.ts` was about a different context. Do NOT flag this as a bug.

---

## Subscription multi-item UI audit (feat/billing-fase1, 2026-06-30)

### CRÍTICO: double-fetch en useSubscription.ts
`useSubscription.ts:76-79` — `loadDetails()` y `loadPlans()` se disparan en el mismo useEffect; ambas llaman internamente `fetchConfigSubscription()` → dos GET /config/subscription concurrentes por montaje. `SubscriptionPage` no consume `availablePlans` ni `isLoadingPlans`. Fix: consolidar en una sola llamada a `fetchConfigSubscription()` o convertir `loadPlans` en lazy.

### CRÍTICO: error de red muestra "Sin plan activo" con CTA destructivo
`SubscriptionPage.tsx:240-259` — cuando `loadDetails()` falla, `details` queda null y el componente muestra "Sin plan activo → Seleccionar un plan". Un propietario con suscripción activa puede iniciar un segundo Stripe Checkout por error. Fix: estado `hasLoadError` separado + pantalla de retry antes de la rama `!plan && !multiItem`.

### Patrón correcto reforzado: catálogo LAZY via montaje condicional
Los 4 modales de gestión se montan con `{flag && <Modal .../>}` — no CSS-hidden, sino desmontados. Esto carga el catálogo de complementos SOLO al abrir "Agregar complemento". El comentario en `MultiItemSubscriptionManager` lo documenta explícitamente. Este es el patrón a replicar.

### RadioGroup sin aria-labelledby — patrón RECURRENTE
`ChangeIntervalModal.tsx:89-90` — `<Label>` sin `id` y `<RadioGroup>` sin `aria-labelledby`. Mismo gap visto en chat-admin-channels (2026-06-28). La corrección correcta (fieldset+legend o aria-labelledby) ya existe en `CancelSubscriptionDialog` del módulo de billing super-admin. Siempre verificar en cualquier RadioGroup nuevo.

### "Quitar" buttons múltiples sin aria-label contextual
`MultiItemSubscriptionCard.tsx:107-115` — múltiples botones con el mismo nombre accesible "Quitar". Fix: `aria-label={`Quitar ${pkg.name}`}` en cada botón. Patrón recurrente en botones de acción por fila (AccessControl, Chat).

### Interfaces de dominio fuera de schema.ts
`CheckoutSubscriptionResource.ts:30-64` — `IStripeSubscription`, `IMultiItemMutationResult`, `IMySubscription` en el resource file. Deben estar en `schema.ts` con Zod schemas. La violación es real pero no de las más críticas (no causa bugs de runtime).

### intervalSuffix duplicada
`MultiItemSubscriptionCard.tsx:25` y `ProrationPreviewPanel.tsx:17` — misma función local. Sacar a `subscriptionPresentation.tsx`.

### handleCancelConfirm cierra modal independientemente del resultado
`SubscriptionPage.tsx:204-207` — `handleCancel` absorbe errores sin rethrow, así `setCancelModalOpen(false)` ejecuta siempre. Fix: handleCancel retorna boolean; cerrar modal solo si true.
