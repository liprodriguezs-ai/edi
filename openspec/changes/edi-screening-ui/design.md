# Design: EDI Screening UI

Change: `edi-screening-ui`

## 1. Context

The backend (`edi-screening-application`) exposes: `POST /api/screening` (draft + item bank), `GET /api/screening/{id}/items`, `PUT /{id}/answer` (batch idempotent), `POST /{id}/score` (gates: `INCOMPLETE_ANSWERS`, `ALTERNATE_ITEMS_REQUIRED`), `POST /{id}/complete`, `POST /{id}/rescore`, `POST /{id}/invalidate`, Orion read/update (somatometry/notes), and `confirmatory_test` CRUD. Stable domain error codes with Spanish `message`. Frontend stack: React 19 + Vite + Tailwind v4 + shadcn, `createEntityStore`/`createCrudApi`/`useBaseList` scaffolding, `ResponsiveDataTable`, `SearchSelect`, `useLazyCatalogs`, `boneyard-js`, `sileo` toast. Existing modules reused for context/navigation: `child`, `visit`, `healthCenter`, `practitioner`, `instrument`.

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | `ScreeningApplyPage` uses a plain Zustand store `screeningDraftStore` (with devtools) + one orchestration hook `useScreeningApplication()`; NOT `useBaseCrud`, NOT `createEntityStore` | The form's fields are server-determined per screening, there is a mid-flow 422 round-trip, and the result is server-owned. This is a workflow, not an entity collection — the "stores without factory" anti-pattern targets entity CRUD stores, which `screeningStore` (list/detail) still uses |
| D2 | Routes: `/screening`, `/screening/:id`, `/screening/apply/:visitId` (create), `/screening/:id/apply` (resume). Create runs once behind `useInitOnce`; `VISIT_ALREADY_SCREENED` resolves the existing screening (Orion list filtered by `visit_id`) and redirects | Create-on-mount must survive StrictMode double-invoke and stale deep links; resume must never re-create. Completed/invalidated hitting the apply route redirect to detail |
| D3 | Autosave = pending maps (`answers` by `screening_item_id`, `riskFactors` by `risk_factor_id`, last-write-wins) + one debounced flush (750 ms) building a single `PUT /answer` batch; in-flight guard re-queues new edits; failure retains the queue and retries with backoff 1s→2s→4s…cap 15s; flush barrier (awaited) before score/complete; best-effort flush on `visibilitychange` | The endpoint is batch + idempotent by design — replaying is safe, so the client optimizes for burst coalescing and offline tolerance instead of per-field requests. UI status: `idle/saving/saved/offline/frozen` |
| D4 | `buildAnswerSchema(items)` returns a `z.object` mapping item id → `z.enum([...optionIds])`; used for per-answer validation and local completeness (progress). Locked items are excluded from local completeness (server derives them); risk factors are optional (unanswered counts zero, Q1 default of the API) | The item set varies per group/version — a static schema would be a lie. Local completeness only ENABLES the score button; the server remains the gate (422s are handled anyway) |
| D5 | On 422 `ALTERNATE_ITEMS_REQUIRED`, the store merges the returned missing items into the affected areas as `is_alternate: true, required: true` and renders `AlternateEvaluationBlock` inside each `AreaSection`; their answers flow through the same queue. The client never predicts the trigger (options carry no colors — it cannot) | The 422 payload (per area: items with id, code, question, colorless options) is the single source for the alternate round; re-fetching `GET /items` is the fallback if the payload ever proves insufficient |
| D6 | After a successful score, the wizard re-fetches `GET show` with result includes (`area_results`, `findings`, `score_runs`, catalogs) and renders from that canonical shape — never from the score response body | Decouples the UI from the score endpoint's response envelope; one rendering path serves wizard result phase and detail page |
| D7 | Detail = React Query hook `useScreeningDetail(id)` (pattern: `useClientDetail`) with the includes allowlist; wizard state NEVER feeds the detail | Read paths stay cache-managed and shareable; the wizard store dies with the wizard |
| D8 | List: standard `useBaseList` + `ResponsiveDataTable` (breakpoint `lg`), lazy filters (`SCREENING_STATUS`, `RESULT_COLOR`, `HEALTH_CENTER`); CTA opens `ApplyVisitPickerDialog` (remote `SearchSelect` over the visit resource); visit rows gain an "Aplicar tamizaje" action gated by `create.screening` | Both entry points reuse existing surfaces; no new backend endpoint. A visit already screened resolves to redirect (D2) instead of erroring |
| D9 | Color styling keys ONLY off `result_color.key` (`green`/`yellow`/`red`) through one `SemaphoreBadge` atom (module-local); no hex-by-option maps anywhere | Enforces REQ-SUI-006 mechanically — a single component to review for the "never derive" invariant |
| D10 | Confirmatory test: `ConfirmatoryTestResource` (`createCrudApi`, basePath `/confirmatory-test`), section + form modal inside detail, visible only for red + `completed`, catalog loaded on modal open | The API enforces applicability (422); the UI mirrors it to avoid dead-end forms. Menu row stays hidden (spec delta) |
| D11 | Rescore modal: `reason` (required textarea), `authoritative` switch default OFF; instrument version picker (from the instrument module's version resource, published only) enabled ONLY when non-authoritative; authoritative always sends the screening's own version implicitly | Mirrors REQ-SCR-010: simulations may roam versions, mutations are pinned (422 `VERSION_PINNED` otherwise). Defaulting OFF keeps mutation opt-in |
| D12 | Centralized copy maps in `utils/`: `screeningErrors.ts` (code → Spanish message override, falling back to server `message`), `findingCopy.ts` (`finding_type.key` + context → sentence), `lockedReasonCopy.ts` (`auto_derivation_key` + source/value → sentence) | Server sends Spanish messages, but structured keys (findings, locked reasons) are UI-owned copy by API design (D13 of the backend change) |
| D13 | Freeze/staleness: any 409 → drop queue, re-fetch, render read-only + toast; answer/somatometry edit while `scored` → optimistic clear of the result phase + staleness notice (server reverts to `draft` per its D14) | The UI never argues with the FSM; it converges on re-fetch. Optimistic clearing prevents showing a result that no longer corresponds to inputs |
| D14 | Somatometry lives in the wizard header as debounced Orion `update` calls; a successful save re-fetches `GET /items` to refresh locked flags (head circumference) | Lock state is a function of stored somatometry; only the server knows the plausibility bands' effect |
| D15 | Tests mock at the Resource layer (`vi.mock` of module services) with fixtures for bank/results payloads, following the existing `InstrumentVersionDetailPage.test.tsx` approach; no MSW introduction | Consistency with the installed test style; fixtures double as living documentation of the consumed payloads |

## 3. Module surface

```
frontend/src/modules/screening/
├── schema.ts                          # interfaces + somatometry/notes Zod + confirmatory Zod (REQ-SUI-011)
├── ScreeningListPage.tsx
├── ScreeningList.tsx
├── ScreeningDetailPage.tsx
├── ScreeningDetail.tsx
├── ScreeningApplyPage.tsx             # wizard (single custom surface)
├── components/
│   ├── ScreeningTableRow.tsx          # memo + checkMemoListProps
│   ├── SemaphoreBadge.tsx             # ONLY place mapping result_color.key → styles (D9)
│   ├── SemaphoreTable.tsx             # per-area results, read-only server data
│   ├── GlobalResultCard.tsx           # color + message + referral bullets + counters
│   ├── PreviousComparison.tsx         # previous color → current + situation (+ sis_code secondary)
│   ├── FindingsList.tsx
│   ├── ScreeningTimeline.tsx          # score_runs history
│   ├── AreaSection.tsx                # collapsible area with progress
│   ├── ItemRow.tsx                    # 2-option radio, neutral, locked variant
│   ├── AlternateEvaluationBlock.tsx   # inserted on 422 (D5)
│   ├── RiskFactorSection.tsx          # FRB yes/no rows, locked variant
│   ├── SomatometryFields.tsx          # debounced update + items re-fetch (D14)
│   ├── SaveStatusIndicator.tsx        # idle/saving/saved/offline/frozen
│   ├── ApplyVisitPickerDialog.tsx     # remote SearchSelect (D8)
│   ├── ConfirmatoryTestSection.tsx    # + form modal (D10)
│   ├── RescoreModal.tsx               # reason + authoritative + version picker (D11)
│   └── InvalidateModal.tsx            # reason
├── hooks/
│   ├── useScreeningList.ts            # useBaseList wiring
│   ├── useScreeningDetail.ts          # React Query show + includes (D7)
│   └── useScreeningApplication.ts     # wizard orchestration (D1–D6, D13, D14)
├── services/
│   ├── ScreeningResource.ts           # createCrudApi + customMethods: items/answer/score/complete/rescore/invalidate
│   └── ConfirmatoryTestResource.ts
├── store/
│   ├── screeningStore.ts              # createEntityStore<IScreening>('screening')
│   └── screeningDraftStore.ts         # plain Zustand (D1/D3)
└── utils/
    ├── buildAnswerSchema.ts
    ├── screeningErrors.ts
    ├── findingCopy.ts
    └── lockedReasonCopy.ts
```

Touched outside the module: `App.tsx` (4 routes), `useCatalogs.ts` (3 `CatalogType` members), `iconMap.ts` (icon), `modules/visit/` (row action), backend `SystemTableSeeder.php` (visibility) + `AngularController.php` (3 mapper entries) + the menu-visibility Feature test.

## 4. Draft store shape (wizard)

```ts
interface ScreeningDraftState {
  screening: IScreening | null            // header incl. status, somatometry, ages
  bank: IScreeningItemBank | null         // areas → items (+inserted alternates), risk factors
  answers: Record<string, string>         // screening_item_id → screening_item_option_id (optimistic)
  riskFactorAnswers: Record<string, boolean>
  pending: { answers: Record<string, string>; riskFactors: Record<string, boolean> }  // flush queue (D3)
  syncStatus: 'idle' | 'saving' | 'saved' | 'offline' | 'frozen'
  scorePhase: 'editing' | 'scoring' | 'result'
  alternateRequired: Record<string, IBankItem[]>  // development_area_id → inserted items (D5)
  incompleteItemIds: string[]             // from 422 INCOMPLETE_ANSWERS
  // actions: hydrate, selectAnswer, selectRiskFactor, enqueue/flush, markFrozen,
  // insertAlternates, clearResult (staleness), reset
}
```

`useScreeningApplication()` owns: create/resume hydration (D2), the debounce/backoff timers, the flush barrier, `score()`, `complete()`, and navigation on freeze. Components stay presentational.

## 5. API consumption map

| Endpoint | Consumer | Errors handled |
|---|---|---|
| `POST /screening` | apply route (create) | `SCREENING_NOT_APPLICABLE` (message screen), `VISIT_ALREADY_SCREENED` (redirect), 422 somatometry field errors |
| `GET /screening/{id}` + includes | detail hook, wizard hydrate/result (D6) | 404 → notFound state |
| `GET /screening/{id}/items` | resume, post-somatometry refresh (D14) | — |
| `PUT /screening/{id}/answer` | flush queue (D3) | 409 `SCREENING_FROZEN` (freeze, D13), 422 offending ids (surface + drop those entries) |
| `PATCH/PUT screening update` | somatometry/notes (D14) | 409 frozen, 422 ranges |
| `POST /screening/{id}/score` | score action | 422 `INCOMPLETE_ANSWERS`, 422 `ALTERNATE_ITEMS_REQUIRED` (D5), 409 frozen |
| `POST /screening/{id}/complete` | complete action | 409 `INVALID_STATUS_TRANSITION`/frozen |
| `POST /screening/{id}/rescore` | RescoreModal (D11) | 403, 422 reason/`VERSION_PINNED`, 409 `RULES_FINGERPRINT_MISMATCH` |
| `POST /screening/{id}/invalidate` | InvalidateModal | 403, 409 |
| `confirmatory-test` CRUD | ConfirmatoryTestSection (D10) | 422 `CONFIRMATORY_NOT_APPLICABLE` |
| `POST /selects` | lazy filters + confirmatory form | — |

All toasts via `sileo`; unknown codes fall back to the server `message` (`screeningErrors.ts`).

## 6. Registration wiring (exact)

- `App.tsx`: `<Route element={<PermissionRoute permissionEntity='screening' />}>` wrapping the 4 routes (list, detail, apply/:visitId, :id/apply).
- `useCatalogs.ts`: `SCREENING_STATUS = 'screening_status'`, `RESULT_COLOR = 'result_color'`, `CONFIRMATORY_TEST_RESULT = 'confirmatory_test_result'`.
- `AngularController::$mapperSelects`: `'ScreeningStatus' => ScreeningStatus::class`, `'ResultColor' => ResultColor::class`, `'ConfirmatoryTestResult' => ConfirmatoryTestResult::class` (StudlyCase of the snake keys — `Str::studly` coupling).
- `SystemTableSeeder::seedModuleVisibility()`: remove `Tamizajes` from `$hidden`; keep `Pruebas Confirmatorias`.
- `iconMap.ts`: ensure the icon named by `seedModuleIcons` for `Tamizajes` exists (`IconReportMedical` per the backend change); if the icon set lacks it, pick the nearest and update the seeder in the same commit so both sides agree.
- Update the prior menu-visibility Feature test to the new expectation (Tamizajes visible, Pruebas Confirmatorias hidden).
- NO permission seeding (the 13 rows already exist), NO new modules.csv rows.

## 7. Testing plan

| Layer | Coverage |
|---|---|
| Vitest unit | `buildAnswerSchema` (enum per item, foreign option rejected, locked exclusion); draft store (last-write-wins merge, single-batch coalescing, backoff retention, alternate insertion, freeze discard, staleness clear) |
| Vitest component | `SemaphoreTable`/`GlobalResultCard`/`PreviousComparison`/`ScreeningTimeline` from fixtures (colors only from payload); `ItemRow` neutral + locked variants; wizard create-once guard (StrictMode) and `VISIT_ALREADY_SCREENED` redirect (mocked resources, D15) |
| Backend Feature (registration) | adjusted menu test; `/selects` returns the 3 new catalogs (studly coupling proof) |
| Manual/Playwright smoke | full apply flow against the local stack (draft → autosave → alternate 422 → score → complete), offline toggle during answering |

Gates: `pnpm exec tsc -p tsconfig.app.json --noEmit` · `pnpm exec vitest run` (221 baseline + new) · `pnpm build` · `eslint` · backend host command (737 baseline, serial re-run on flake per AGENTS.md).

## 8. Open decisions (defaults encoded)

| Q | Question | Default |
|---|---|---|
| Q1 | Debounce/backoff constants | 750 ms debounce; backoff 1s→15s cap. Tune with field feedback |
| Q2 | Entry points | Visit-row action + list CTA visit picker; no standalone "new" form |
| Q3 | Per-item colors on detail after scoring | Not shown (area-level + findings only); FUA change owns the full per-item projection |
| Q4 | What-if rescore version picker | Included, minimal (published versions), only when non-authoritative |
| Q5 | Bank payload field names | Interfaces follow REQ-SCR-007 semantics; apply phase MUST verify exact serializer field names against the live response and align `schema.ts` before building on them |
