# Spec: EDI Screening UI

Change: `edi-screening-ui`
Capabilities: `screening-ui` (new), `screening-application` (modified — registration delta)

> RFC 2119 keywords (MUST, MUST NOT, SHALL, SHOULD, MAY) are normative.
> "The API" = the `edi-screening-application` endpoints and error codes (REQ-SCR-001..014), consumed as-is; no backend behavior changes here.
> All UI copy in Mexican Spanish; all identifiers in English; zero `any`.
> House conventions apply: skeletons via `boneyard-js`, toasts via `sileo` (`toast` from `@/shared/hooks/useToast`), dates via `displayFormatDate()`, lazy catalogs (§2.12 of the react skill).
> On archive, REQ-SUI-* merge into `openspec/specs/screening-ui/spec.md`.

---

## ADDED Requirements — capability `screening-ui`

### REQ-SUI-001 — Module scaffolding, routes, and permissions

The system SHALL add `frontend/src/modules/screening/` following the full-entity pattern (react skill §2.2–2.10) and register routes in `App.tsx` under `<PermissionRoute permissionEntity='screening'>`: `/screening` (list), `/screening/:id` (detail), `/screening/apply/:visitId` (wizard, create), `/screening/:id/apply` (wizard, resume). The list and detail MUST use the shared scaffolding: `createEntityStore('screening')`, `createCrudApi` with custom methods (`items`, `answer`, `score`, `complete`, `rescore`, `invalidate`), `useBaseList`, `ResponsiveDataTable`, `useMultiplePermissions`/`PermissionGuard` gating every action (`create/update/delete/rescore` + `confirmatory_test` verbs). No barrel files.

#### Scenario: routes are permission-gated
- GIVEN a user without `read.screening`
- WHEN they navigate to `/screening` or `/screening/{id}`
- THEN `PermissionRoute` denies access (redirect to `/acceso-denegado`), and no screening request is issued.

### REQ-SUI-002 — Screening list

`ScreeningList` SHALL render server-paginated screenings with columns: child (full name), visit date (`displayFormatDate`) and folio (`visit.receipt_number`), health center, status badge, global result color, and situation — via `initialSearch.includes` limited to the API allowlist (`visit`, `child` and catalog relations). Filters MUST load lazily (`useLazyCatalogs` + `onFirstOpen`): status (`SCREENING_STATUS`), global color (`RESULT_COLOR`), health center (`HEALTH_CENTER`). Row actions by state: `draft`/`scored` → "Continuar aplicación" (navigates to `/screening/:id/apply`); all → "Ver detalle". The primary CTA "Aplicar tamizaje" (gated by `create.screening`) SHALL open a visit picker dialog using `SearchSelect` remote search over the existing visit resource; picking a visit navigates to `/screening/apply/:visitId`. There is NO generic create/edit modal and NO destroy action.

#### Scenario: filters do not fire /selects on mount
- WHEN `/screening` mounts
- THEN no `POST /selects` request is issued until a filter dropdown is first opened.

#### Scenario: draft rows resume, not re-create
- GIVEN a `draft` screening row
- WHEN the user clicks "Continuar aplicación"
- THEN the app navigates to `/screening/:id/apply` and no `POST /api/screening` is issued.

### REQ-SUI-003 — Apply wizard lifecycle (create, resume, render)

`ScreeningApplyPage` SHALL NOT use `useBaseCrud`. On `/screening/apply/:visitId` it MUST create the draft exactly once (`useInitOnce`-guarded against StrictMode double-invoke) via `POST /api/screening { visit_id }`, then hydrate `screeningDraftStore` from the 201 response (screening + item bank). Error handling: 422 `SCREENING_NOT_APPLICABLE` → non-blocking screen with the server's Spanish message and a back action, nothing persisted client-side; 422 `VISIT_ALREADY_SCREENED` → resolve the existing screening for that visit (list query by `visit_id`) and redirect to `/screening/:id/apply` (draft/scored) or `/screening/:id` (completed/invalidated). On `/screening/:id/apply` it MUST hydrate from `GET show` + `GET /items`. The wizard renders: a header (child, chronological/corrected age, applied group with a substitution notice when `applied_age_group_id ≠ age_group_id`), somatometry fields, one `AreaSection` per bank area in server order with `ItemRow` per item in position order, a risk-factor section, a persistent save-status indicator, and the score bar.

#### Scenario: StrictMode does not double-create
- WHEN `/screening/apply/:visitId` mounts under React StrictMode
- THEN exactly one `POST /api/screening` is issued.

#### Scenario: resume renders prior answers
- GIVEN a draft with 10 saved answers
- WHEN `/screening/:id/apply` loads
- THEN the 10 answers are pre-selected from `GET show` includes and the progress count reflects them.

### REQ-SUI-004 — Autosave: debounced, batched, idempotent, offline-tolerant

Answer selections and risk-factor answers SHALL be applied optimistically to the store and enqueued in a pending map (last-write-wins per `screening_item_id`/`risk_factor_id`). A debounced flush (default 750 ms) sends ONE `PUT /api/screening/{id}/answer` batch with all pending entries; a flush MUST also run before score/complete and when the page is hidden (best-effort). While a flush is in flight, new selections re-enter the queue (no interleaved batches). On network failure the queue is retained and retried with exponential backoff (1s→2s→4s… cap 15s) and the indicator shows "Sin conexión — se reintentará"; on success "Guardado". On 409 `SCREENING_FROZEN` the wizard MUST stop autosaving, discard the queue, re-fetch the screening, and switch to read-only. Somatometry/notes edits save through the Orion `update` route with the same debounce; a somatometry save MUST re-fetch `GET /items` because locked flags (head circumference) depend on it.

#### Scenario: burst of answers becomes one batch
- WHEN the user answers 5 items within 750 ms
- THEN exactly one `PUT /answer` request carries the 5 answers.

#### Scenario: offline answers are not lost
- GIVEN 3 answers enqueued while the network is down
- WHEN connectivity returns
- THEN the retried batch persists them and the indicator returns to "Guardado" without user action.

### REQ-SUI-005 — Score round-trip and alternate insertion

The score action SHALL: await a final flush, then `POST /api/screening/{id}/score`. On 422 `ALTERNATE_ITEMS_REQUIRED` the wizard MUST insert an `AlternateEvaluationBlock` under EACH affected `AreaSection`, rendering the missing items from the response payload (id, code, question, options without colors) marked as required, with the explanation copy ("Toda el área resultó amarilla; se aplican los reactivos del grupo anterior"), scroll to the first inserted block, and re-enable score once all inserted items are answered (answers travel through REQ-SUI-004). On 422 `INCOMPLETE_ANSWERS` the wizard MUST highlight the missing items and scroll to the first. On success the wizard shows the result phase: `SemaphoreTable` (per-area results), `GlobalResultCard` (color, message, referral bullets), findings — all hydrated by re-fetching `GET show` with result includes (canonical shape, no coupling to the score response body). The client MUST NOT predict the alternate trigger (it cannot: options carry no colors).

#### Scenario: 422 inserts and retries
- GIVEN a score attempt returning 422 `ALTERNATE_ITEMS_REQUIRED` for MF
- WHEN the user answers the listed alternate items and scores again
- THEN the second `POST /score` succeeds and the result phase renders server data.

#### Scenario: alternate answers are just answers
- WHEN an inserted alternate item is answered
- THEN it is enqueued and sent through the same idempotent batch endpoint as own items.

### REQ-SUI-006 — Server-truth rendering contract (never derive)

The UI MUST NOT compute, predict, or derive any result: no option→color mapping, no counting toward thresholds, no area/global/situation logic. `ItemRow` renders two neutral options with no color hints at any phase. Area semaphores, the global color, counters, situation, and messages render EXCLUSIVELY from server payloads (`area_results`, header fields, catalog relations). The module MUST NOT hardcode color hex values keyed by option; visual color styles key ONLY off the server-provided `result_color.key` (`green|yellow|red`).

#### Scenario: no derivation code exists
- WHEN the module is reviewed/tested
- THEN no code path maps a `screening_item_option_id` to a color, and a fixture-rendered `SemaphoreTable` shows exactly the colors present in the `area_results` payload.

### REQ-SUI-007 — Freeze and staleness semantics

The wizard and detail MUST mirror the API FSM: editing an answer or somatometry while `scored` reverts the screening to `draft` server-side — the UI MUST clear the displayed result and show a notice ("El resultado quedó desactualizado; vuelve a calificar"). "Completar" is available only in `scored`, behind a `ConfirmDialog`, calls `POST /complete`, and on success renders the frozen state. Any 409 (`SCREENING_FROZEN`, `INVALID_STATUS_TRANSITION`) MUST re-fetch the screening and render read-only with a Spanish toast — never retry writes automatically. `completed`/`invalidated` screenings render the wizard route as read-only (redirect to detail).

#### Scenario: editing after scoring invalidates the shown result
- GIVEN a `scored` wizard showing the result phase
- WHEN the user changes one answer
- THEN after the autosave flush the result phase is cleared, the staleness notice appears, and the score button is available again.

### REQ-SUI-008 — Detail page

`ScreeningDetail` SHALL render from `GET show` with includes: header identity (child, visit folio/date, center, practitioner, ages, applied group + substitution note), status badge, `SemaphoreTable` per area (color, pooled counts, thresholds), `GlobalResultCard` (color, Spanish message, referral bullets, `alert_sign_count`, `risk_factor_count`), findings list (Spanish copy per `finding_type.key` + context), previous-test comparison (previous color → current color and situation label; `sis_code` shown as secondary text), and `ScreeningTimeline` from `score_runs` (date, trigger, authoritative flag, instrument version, engine version, run color, reason). Actions, permission-gated: "Continuar aplicación" (draft/scored), "Completar" (scored), "Recalcular" (`rescore.screening`; modal requiring `reason`, `authoritative` switch default OFF, version picker enabled only when non-authoritative), "Invalidar" (`delete.screening`; modal requiring `reason`). Loading states use `boneyard-js` skeletons.

#### Scenario: rescore defaults to simulation
- WHEN the rescore modal submits with defaults plus a reason
- THEN the request carries `authoritative: false` and the header result shown does not change; the timeline gains one run.

#### Scenario: draft detail has no result
- GIVEN a `draft` screening
- WHEN the detail renders
- THEN semaphore/global/situation sections show an empty state ("Aún sin calificar") and no result values.

### REQ-SUI-009 — Auto-derived items UX

Items and risk factors flagged locked in the bank SHALL render read-only (radio disabled, lock affordance) with Spanish copy resolved from the structured `locked_reason` (`auto_derivation_key` + source field + value), e.g. head circumference and FRB3/FRB4/FRB7. Unlocked derivable items (unknown source) stay answerable. After scoring, answers persisted with `is_auto_derived = true` over a manual answer MUST surface the override: the `auto_answer_overrode_manual` finding is listed and the affected row shows a "respuesta derivada automáticamente" marker.

#### Scenario: locked item explains itself
- GIVEN a bank item locked with `auto_derivation_key = head_circumference` and a source value
- WHEN the wizard renders it
- THEN its options are disabled and the visible reason includes the measured value.

### REQ-SUI-010 — Confirmatory test capture

`ScreeningDetail` SHALL embed a confirmatory-test section visible only when the screening is `completed` AND its global color is red: shows the existing record (test name, `applied_at` via `displayFormatDate`, result from the `CONFIRMATORY_TEST_RESULT` catalog, notes) or a create action (gated by `create.confirmatory_test`). The form (modal, static Zod schema) loads its catalog on open (never eager) and submits via a dedicated `ConfirmatoryTestResource`. A 422 `CONFIRMATORY_NOT_APPLICABLE` renders the server message as a toast. No standalone page, route, or menu entry is added.

#### Scenario: section hidden when not applicable
- GIVEN a `completed` green screening
- WHEN the detail renders
- THEN no confirmatory-test section (or create action) is visible.

### REQ-SUI-011 — Types and runtime answer schema

`schema.ts` SHALL contain ONLY: entity interfaces (`IScreening`, `IScreeningAnswer`, `IScreeningAreaResult`, `IScreeningFinding`, `IScreeningScoreRun`, `IConfirmatoryTest`, bank types `IScreeningItemBank`/`IBankArea`/`IBankItem`/`IBankRiskFactor`, extending/composing `IEntity` with inline relation objects), the static somatometry/notes Zod schema (ranges mirroring the API: weight 1500–40000 g, height 350–1400 mm, head circumference 250–700 mm, Spanish messages), and the confirmatory-test form schema. The answer schema MUST be built at runtime by `buildAnswerSchema(items)` → per item a `z.enum` of that item's option ids. No `any`, no `IXxxResponse` wrappers, no local duplicate interfaces; typed error envelope `IScreeningApiError { message, code, ...context }` discriminated by `code`.

#### Scenario: runtime schema rejects foreign options
- GIVEN a bank item with options A and B
- WHEN `buildAnswerSchema` validates an answer with option id C
- THEN validation fails; A and B pass.

### REQ-SUI-012 — Quality gates and tests

The change SHALL add Vitest coverage for: `buildAnswerSchema`, the draft store (queue merge, flush batching, backoff retention, 422 alternate insertion, freeze handling), `SemaphoreTable`/`GlobalResultCard`/`ScreeningTimeline` rendering from fixtures, and the wizard create/resume guards. All suites MUST pass: `pnpm exec tsc -p tsconfig.app.json --noEmit`, `pnpm exec vitest run` (baseline 221 + new), `pnpm build`, `eslint`. Backend suite stays green (737) after the registration edits, including the adjusted menu-visibility test.

#### Scenario: frontend gates green
- WHEN the frontend gates run on the final candidate
- THEN type-check, tests, lint, and build all pass with zero `any` introduced.

---

## MODIFIED Requirements — capability `screening-application`

### REQ-SCR-013 (delta) — Registration completes: menu visible, selects pair lands

The `Tamizajes` module row SHALL become visible (`seedModuleVisibility` no longer lists it in `$hidden`); `Pruebas Confirmatorias` stays hidden. `AngularController::$mapperSelects` SHALL gain `'ScreeningStatus'`, `'ResultColor'`, `'ConfirmatoryTestResult'` and `useCatalogs.ts::CatalogType` the matching `SCREENING_STATUS = 'screening_status'`, `RESULT_COLOR = 'result_color'`, `CONFIRMATORY_TEST_RESULT = 'confirmatory_test_result'` members — in this same change (string-coupled pair). The `Tamizajes` icon name seeded by `seedModuleIcons` MUST exist in `frontend/src/shared/utils/iconMap.ts` (align either side if needed). The prior "menu is silent" test is updated to assert the new expectation: `Tamizajes` visible, `Pruebas Confirmatorias` hidden.

#### Scenario: menu shows Tamizajes after seeding
- WHEN `GET /api/menu` runs for a tenant role with `read.screening` after re-seeding
- THEN a `Tamizajes` entry appears with route `/screening` and its icon; no `Pruebas Confirmatorias` entry appears.

#### Scenario: selects pair is wired
- WHEN the frontend requests `POST /selects` with `screening_status`, `result_color`, or `confirmatory_test_result`
- THEN each returns its catalog rows (non-empty after seeding), proving the `Str::studly` coupling matches.
