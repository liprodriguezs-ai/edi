# SDD Tasks — Anuncios: rich text, página dedicada y estadísticas de lectura

- **Change:** `announcements-richtext-read-stats`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** tasks
- **Artifact store:** hybrid
- **Date:** 2026-07-04
- **TDD mode:** Standard (tests written alongside, not TDD-first) unless the project's global `strict_tdd` setting says otherwise for `apply`
- **Depends on:** `proposal.md`, `design.md` (this change)
- **Caveat:** changes span two git submodules (`backend`, `frontend`) — no worktrees for submodule edits, per project convention.

---

## Work-unit overview

| # | Work unit | Commit boundary | Parallelizable after |
|---|-----------|-----------------|----------------------|
| WU-1 | Backend — rich text (migration, sanitizer, model, requests, service, controller) | `feat(announcements): add sanitized rich-text content_html field` | Start immediately |
| WU-2 | Backend — read statistics (service, controller, route) | `feat(announcements): add read-stats endpoint` | Start immediately (parallel with WU-1) |
| WU-3 | Frontend — shared rich-text components | `feat(richtext): add shared RichTextEditor and RichTextContent` | Start immediately (parallel with WU-1/WU-2) |
| WU-4 | Frontend — Sheet → Page (form extraction, editor page, routing) | `feat(announcements): move compose/edit to a dedicated page` | After WU-1 (needs `content_html` in the contract), WU-3 |
| WU-5 | Frontend — read statistics panel | `feat(announcements): add read-stats panel` | After WU-2, WU-4 |
| WU-6 | Frontend — "pro" redesign (reader + feed card) | `feat(announcements): redesign reader and feed card` | After WU-3, WU-4 |
| WU-7 | Verify + quality gates + review | `chore: run quality gates for announcements-richtext-read-stats` | After WU-1 to WU-6 |

---

## WU-1 — Backend: rich text

All files in `backend/`.

### 1.1 Shared sanitizer

- [ ] **1.1.1** Read `backend/Modules/Chat/app/Services/ChatHtmlSanitizer.php` in full (already read this phase — confirm no drift before copying).
- [ ] **1.1.2** Create `backend/app/Services/RichTextSanitizer.php` in namespace `App\Services`, copying the `CONFIG` constant, `sanitize()`, and `toPlainText()` methods verbatim. Do NOT modify or delete `ChatHtmlSanitizer` — it stays as-is (migrating Chat is out of scope).

### 1.2 Migration

- [ ] **1.2.1** Create `backend/Modules/Announcements/database/migrations/2026_07_04_090000_add_content_html_to_announcement_table.php`:
  ```php
  Schema::table('announcement', function (Blueprint $table): void {
      $table->text('content_html')->nullable()->after('content')
          ->comment('Sanitized rich-text HTML for display; content holds the plain-text derivation used for search + notification excerpts.');
  });
  ```
  Include a `down()` that drops the column.
- [ ] **1.2.2** Run `php artisan migrate` (host php, Postgres `127.0.0.1:5432`) and verify the column exists.

### 1.3 Model

- [ ] **1.3.1** Open `backend/Modules/Announcements/app/Models/Announcement.php`. Do **NOT** add `content_html` to `$fillable`. No cast needed. Add a one-line comment above `$fillable` noting `content_html` is deliberately excluded (server-only, set via `AnnouncementService::applyRichContent()`).

### 1.4 Requests

- [ ] **1.4.1** `AnnouncementCreateRequest.php`: change `'content' => ['required', 'string']` to `'content' => ['nullable', 'string']`; add `'content_html' => ['required', 'string', 'max:20000']`. Keep array style (matches file convention).
- [ ] **1.4.2** `AnnouncementUpdateRequest.php`: change `'content' => ['sometimes', 'required', 'string']` to `'content' => ['sometimes', 'nullable', 'string']`; add `'content_html' => ['sometimes', 'required', 'string', 'max:20000']`.

### 1.5 Service

- [ ] **1.5.1** `AnnouncementService.php`: add `use App\Services\RichTextSanitizer;` import. Add a **public** method:
  ```php
  public function applyRichContent(Announcement $announcement, string $html): void
  {
      $clean = RichTextSanitizer::sanitize($html);
      $announcement->content_html = $clean;
      $announcement->content = RichTextSanitizer::toPlainText($clean);
  }
  ```
- [ ] **1.5.2** In `update()`, before `$announcement->fill($attributes)`, add:
  ```php
  if (array_key_exists('content_html', $attributes)) {
      $this->applyRichContent($announcement, $attributes['content_html']);
      unset($attributes['content_html'], $attributes['content']);
  }
  ```
  Verify `notifyReadersOfEdit`/`dispatchPublishedNotifications` still work unchanged (they read `strip_tags($announcement->content)`, which is now already-plain text — a harmless no-op, no code change needed there).

### 1.6 Controller

- [ ] **1.6.1** `AnnouncementController::performStore`: extract `content_html` from `$data` before `fill()`, unset both `content_html` and `content` from `$data`, then after setting `announcement_status_id`/`author_user_id` (and before `save()`) call `app(AnnouncementService::class)->applyRichContent($entity, $contentHtml)` when `$contentHtml !== null`. See exact snippet in `design.md` §2.6.
- [ ] **1.6.2** Confirm `searchableBy()` still returns `['title', 'content']` — no change.

### 1.7 Backend tests

- [ ] **1.7.1** Create `backend/Modules/Announcements/tests/Feature/AnnouncementRichContentTest.php`:
  - Create an announcement via the API with `content_html = '<script>alert(1)</script><strong>hola</strong>'` → assert response/DB `content_html` contains `<strong>hola</strong>` and does NOT contain `<script>`; assert `content` equals the derived plain text (`"hola"`, trimmed).
  - Update an existing announcement's `content_html` → same sanitize/derive assertions; assert `title`/other fields untouched when only `content_html` sent.
  - Mass-assignment guard: directly attempt `Announcement::factory()->create(['content_html' => '<script>x</script>'])` via a factory/create call bypassing the service — assert the raw value is NOT persisted verbatim through `create()` (i.e. `$fillable` protection holds; only `applyRichContent()` sets it).
  - Tenant isolation: existing pattern (2 tenants) — not required to be new here since no cross-tenant query is introduced by this slice, but confirm the create/update tests run under a single tenant fixture consistent with sibling tests in the file.

---

## WU-2 — Backend: read statistics

All files in `backend/Modules/Announcements/`.

### 2.1 Preparation

- [ ] **2.1.1** Re-confirm `AnnouncementService::audienceUserIds()` and `audienceUserIdsFor()` signatures (already read this phase — no change needed to either).
- [ ] **2.1.2** Confirm `Modules\Housing\Models\UnitUser` relations `unit()` and `user()` (already read — `user()` bypasses `UserTenantScope` since `User` is central).
- [ ] **2.1.3** Confirm `Modules\Housing\Models\Unit::fullIdentifier()` accessor (already read — appended via `$appends`, safe to read as `$unit->full_identifier`).

### 2.2 AnnouncementReadStatsService

- [ ] **2.2.1** Create `backend/Modules/Announcements/app/Services/AnnouncementReadStatsService.php`, namespace `Modules\Announcements\Services`.
  - Constructor: `public function __construct(private readonly AnnouncementService $announcementService) {}`.
  - **Public method: `build(Announcement $announcement, int $page, int $perPage, string $status, ?string $search): array`** — see full pseudocode in `design.md` §4.1:
    1. `$recipientIds = $this->announcementService->audienceUserIds($announcement);`
    2. `$readAtByUser = AnnouncementRead::where('announcement_id', $announcement->id)->whereIn('user_id', $recipientIds)->pluck('read_at', 'user_id');`
    3. Compute `target_people_count`, `read_people_count`, `unread_people_count`, `read_percentage` (guard divide-by-zero → 0).
    4. Branch: `$announcement->target_type === 'board'` → private `boardShape()`; else → private `unitGroupedShape()`.
  - **Private `boardShape(array $recipientIds, Collection $readAtByUser, ?string $search): array`**:
    - `User::whereIn('id', $recipientIds)->withoutGlobalScope(UserTenantScope::class)->orderBy('name')->get(['id', 'name'])`, optionally `->when($search, fn($q) => $q->where('name', 'ilike', "%{$search}%"))` (Postgres `ilike`; confirm the project's case-insensitive-search convention — check a sibling searchableBy/whereRaw LOWER pattern if `ilike` is not the house style).
    - Map to `{ user_id, name, read: bool, read_at }` using `$readAtByUser`.
    - Return `['target_units_count' => null, 'units' => [], 'people' => [...]]`.
  - **Private `unitGroupedShape(array $recipientIds, Collection $readAtByUser, int $page, int $perPage, string $status, ?string $search): array`**:
    - `UnitUser::whereIn('user_id', $recipientIds)->whereNull('end_date')->with(['unit', 'user'])->get()->groupBy('unit_id')` — ONE query, no N+1.
    - `target_units_count = $grouped->count()`.
    - Apply `$search` (unit `full_identifier` OR any person `name`, case-insensitive) to drop non-matching units.
    - Apply `$status` (`read`/`unread`) to narrow the `people[]` shown per unit (drop units left with zero people); `read_count`/`total_count` always reflect the FULL unit, not the filtered view.
    - Sort surviving units by `unit->full_identifier`; paginate with `forPage($page, $perPage)`.
    - Map each unit → `{ unit_id, unit_label, read_count, total_count, people: [...] }`, people sorted by name.
    - Return `['target_units_count' => ..., 'units' => [...], 'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total_units' => <post-filter count>, 'last_page' => ...]]`.

### 2.3 Controller

- [ ] **2.3.1** `AnnouncementController.php`: add `readStats(string $id, Request $request): JsonResponse` — see exact snippet in `design.md` §4.4. Authorize `update.announcement` (NOT `read.announcement` — deliberately stricter, matches `preview`'s gate reasoning). Validate `page`/`per_page`/`status`/`search` inline. Return the bare service payload via `response()->json($stats)` (no `{data: ...}` wrapper).
- [ ] **2.3.2** Add the necessary imports (`AnnouncementReadStatsService`, `Rule` if not already imported — it already is, used by `preview`).

### 2.4 Route

- [ ] **2.4.1** `backend/Modules/Announcements/routes/api.php`: inside the existing `$customMiddleware` group (the one already containing `publish`/`archive`/`read`/`metrics`), add:
  ```php
  Route::get('announcement/{id}/read-stats', [AnnouncementController::class, 'readStats']);
  ```

### 2.5 Backend tests

- [ ] **2.5.1** Create `backend/Modules/Announcements/tests/Feature/AnnouncementReadStatsControllerTest.php`:
  - Seed an announcement with `target_type = 'by_tower'` reaching 2 units / 3 residents (one unit with 2 residents); mark 1 read. Assert `target_units_count=2`, `target_people_count=3`, `read_people_count=1`, `unread_people_count=2`, `read_percentage=33`; assert per-unit `read_count`/`total_count` and per-person `read`/`read_at` shape.
  - Separate case: `target_type = 'board'` → assert `units=[]`, `target_units_count=null`, and a flat `people[]` with correct `read`/`read_at`.
  - `status=unread` narrows `people[]` per unit but leaves `read_count`/`total_count` unchanged.
  - `search` matching a unit label filters correctly; `search` matching a resident name (in a different unit) also surfaces that unit.
  - Pagination: seed >1 page worth of units, assert `meta.last_page > 1` and page 2 returns the remaining units.
  - Permission gate: a user with only `read.announcement` → 403; a user with `update.announcement` → 200.
  - Tenant isolation: tenant B's `announcement_read`/`unit_user` rows never leak into tenant A's response.

---

## WU-3 — Frontend: shared rich-text components

All files under `frontend/src/shared/components/richtext/` (new directory).

- [ ] **3.1.1** Create `RichTextEditor.tsx`:
  - `useEditor({ extensions: [StarterKit] })` — confirmed via TipTap v3 docs that `StarterKit` already bundles the `Link` mark; do NOT add `@tiptap/extension-link` as a new dependency.
  - Controlled API: `{ value: string; onChange: (_html: string) => void; disabled?: boolean }`, syncing `editor.getHTML()` → `onChange` on `onUpdate`, and calling `editor.commands.setContent(value)` when `value` changes externally (e.g. `reset()` on form open) without fighting the user's cursor — guard with an `isSame` check before calling `setContent` to avoid resetting on every keystroke echo.
  - Toolbar: Negrita (`toggleBold`), Cursiva (`toggleItalic`), Subrayado (`toggleUnderline`), Lista con viñetas (`toggleBulletList`), Lista numerada (`toggleOrderedList`), Enlace (prompt for URL via a small popover/input — NOT `window.prompt` per the project's UI conventions; use a lightweight inline input, `setLink({ href })` / `unsetLink()` toggle).
  - No `any`. Reuse `ToolbarButton`-style pattern from `MessageComposer.tsx` (can be copied locally, not exported — it's not part of the shared contract).
- [ ] **3.1.2** Create `RichTextContent.tsx`:
  - Props: `{ html?: string | null; fallbackText?: string | null; className?: string }`.
  - Same `DOMPurify` config as `ChatMessageBubble.tsx`'s `PURIFY_CONFIG` (`ALLOWED_TAGS`/`ALLOWED_ATTR`/`FORCE_BODY`).
  - When `html` present and non-empty → sanitize + `dangerouslySetInnerHTML`, with scoped `[&_ul]`/`[&_ol]`/`[&_p]`/`[&_a]` Tailwind rules for spacing/link color (no `@tailwindcss/typography`).
  - When `html` is null/empty → render `fallbackText` as `<p className="whitespace-pre-wrap">`.
- [ ] **3.1.3** No barrel `index.ts` (barrel files are prohibited per the frontend skill — consumers import each file directly).
- [ ] **3.1.4** `pnpm build` sanity check — zero TypeScript errors in the two new files.

---

## WU-4 — Frontend: Sheet → Page

Directory: `frontend/src/modules/announcement/`.

### 4.1 Schema changes

- [ ] **4.1.1** `schema.ts`: add `content_html?: string | null` to `IAnnouncement`. Replace `content: z.string().min(1, 'El contenido es requerido')` in `announcementFormSchema` with `content_html: z.string().min(1, 'El contenido es requerido')`. Update `buildAnnouncementFormDefaults` to hydrate `content_html: announcement?.content_html ?? ''` instead of `content`.

### 4.2 Resource

- [ ] **4.2.1** `services/AnnouncementResource.ts`: no change needed here for content (the generic `create`/`update` already forward whatever payload is passed) — verify the mutation payload built in the new `AnnouncementForm` sends `content_html` (not `content`).

### 4.3 AnnouncementForm.tsx (new — extracted)

- [ ] **4.3.1** Create `components/AnnouncementForm.tsx`: move everything inside `AnnouncementComposeModal`'s `<SheetContent>` body into this component, dropping the `Sheet`/`SheetContent`/`SheetHeader`/`SheetFooter` wrapper (render a plain `<form>` + a bottom action bar `<div>` instead). Props: `{ mode: CrudMode; announcement?: IAnnouncement; onSaved: () => void }` (no `isOpen`/`onClose`).
- [ ] **4.3.2** Replace the content `<Textarea id="announce-content" .../>` with `<Controller name="content_html" control={control} render={({field}) => <RichTextEditor value={field.value} onChange={field.onChange} disabled={mutation.isPending} />} />`.
- [ ] **4.3.3** Keep the same `useMutation` create/update logic, `SingleTargetSelector`, channel checkboxes, and attachment upload flow verbatim — only the content field and the wrapper chrome change. Payload sent to `create`/`update` uses `content_html: values.content_html` (not `content`).

### 4.4 AnnouncementEditorPage.tsx (new)

- [ ] **4.4.1** Create `AnnouncementEditorPage.tsx`: `useParams<{ id?: string }>()`; `id` absent → create mode; `id` present → `useQuery` (or the existing `find`/`show` resource call) to load the announcement, edit mode.
- [ ] **4.4.2** shadcn `Tabs`: "Contenido" (always rendered) → `<AnnouncementForm mode={...} announcement={...} onSaved={...} />`; "Lecturas" (edit mode only — hidden/disabled in create mode) → `<AnnouncementReadStats announcementId={id!} />` (WU-5).
- [ ] **4.4.3** Wire `onSaved` to match the removed modal's UX (toast + navigation) — check `AnnouncementComposeModal`'s current `onSuccess` toast copy before deciding the exact post-save flow (see `design.md` §7 open item).
- [ ] **4.4.4** Delete `components/AnnouncementComposeModal.tsx` once `AnnouncementListPage` no longer imports it (step 4.5).

### 4.5 Routing + list reconnection

- [ ] **4.5.1** `App.tsx`: add the lazy import and two routes under the existing `permissionEntity="announcement"` block — see exact snippet in `design.md` §5.5.
- [ ] **4.5.2** `AnnouncementListPage.tsx`: "Nuevo aviso" button → `navigate('/announcement/new')`. Row "Editar" action → `navigate(`/announcement/${announcement.id}/edit`)`. Remove `composeOpen`/`modalMode`/`selectedAnnouncement` state and the `{composeOpen && <AnnouncementComposeModal .../>}` block. Keep the `AnnouncementReadDialog` "view" flow (unrelated to editing) untouched.

### 4.6 Frontend tests

- [ ] **4.6.1** `schema` test: `announcementFormSchema` requires `content_html`, not `content`.
- [ ] **4.6.2** `AnnouncementForm` render test: mounts with `RichTextEditor` in place of the old `Textarea`; submitting sends `content_html` in the payload.
- [ ] **4.6.3** Routing smoke test (if the project has a pattern for this — check sibling module tests): `/announcement/new` renders the create form; `/announcement/:id/edit` renders with tabs, "Lecturas" enabled.

---

## WU-5 — Frontend: read statistics panel

Directory: `frontend/src/modules/announcement/`.

### 5.1 Schema

- [ ] **5.1.1** `schema.ts`: add `readStatsPersonSchema`, `readStatsUnitSchema`, `announcementReadStatsSchema`, and `export type IAnnouncementReadStats` — exact shape in `design.md` §5.1. No `any`.

### 5.2 Resource

- [ ] **5.2.1** `services/AnnouncementResource.ts`: add `readStats: (id: string, params: { page?: number; per_page?: number; status?: 'all' | 'read' | 'unread'; search?: string }) => Promise<IAnnouncementReadStats>` to the `customMethods` factory, hitting `GET /announcement/${id}/read-stats` with `params`, returning `r.data` (flat, no wrapper).

### 5.3 Panel component

- [ ] **5.3.1** Create `components/AnnouncementReadStats.tsx`. Props: `{ announcementId: string }`. Local state: `page` (default 1), `status` (default `'all'`), `search` (debounced ~300ms before it hits the query key).
- [ ] **5.3.2** `useQuery({ queryKey: ['announcement-read-stats', announcementId, page, status, search], queryFn: () => AnnouncementResource.readStats(announcementId, { page, status, search }), enabled: <"Lecturas" tab is the active tab> })`. The `enabled` gate is load-bearing — it must NOT fetch while the "Contenido" tab is active (mirrors the existing `useCatalogs({ enabled: isOpen })` lazy-load discipline elsewhere in this module).
- [ ] **5.3.3** Header: "**{read_people_count} de {target_people_count} personas** leyeron ({read_percentage}%)"; "**{target_units_count} unidades** alcanzadas" only rendered when `target_units_count !== null` (hidden for `board` targets).
- [ ] **5.3.4** Filter row: segmented control "Todos / Leídos / No leídos" bound to `status`; search input bound to `search` (debounced). Both trigger a refetch via the query key — NOT client-side array filtering.
- [ ] **5.3.5** List: when `units.length > 0` (or always, for non-board targets) render each unit as an expandable row ("{unit_label} — {read_count}/{total_count} leyeron"), expanding to the `people[]` rows (name, read/unread chip, `displayFormatDate(read_at, 'mediumDate')` when `read_at` present). When the payload has a top-level `people` (board target), render that flat list instead, no unit header.
- [ ] **5.3.6** Pagination: reuse the existing `Pagination` component, driven by `meta.current_page`/`meta.last_page`, calling `setPage`.
- [ ] **5.3.7** Loading: `boneyard-js` `<Skeleton loading={isLoading}>` (never `animate-pulse`). Empty: "Este aviso aún no tiene destinatarios" when `target_people_count === 0`.

### 5.4 Frontend tests

- [ ] **5.4.1** Schema test: parse both payload shapes (unit-grouped and board) with `announcementReadStatsSchema.parse(...)`.
- [ ] **5.4.2** Resource test: mock `apiClient`, assert `readStats(id, { status: 'unread' })` hits `/announcement/{id}/read-stats?status=unread`.
- [ ] **5.4.3** Render smoke tests: loading (Skeleton), populated (unit-grouped — header counts + expandable rows), populated (board — flat people list, no unit header), empty (`target_people_count = 0`).

---

## WU-6 — Frontend: "pro" redesign

### 6.1 AnnouncementReadDialog.tsx

- [ ] **6.1.1** Replace `<p className="whitespace-pre-wrap ...">{announcement?.content}</p>` with `<RichTextContent html={announcement?.content_html} fallbackText={announcement?.content} />`.
- [ ] **6.1.2** Restyle the type/priority/date chip row using `Badge`/`StatusPill` primitives instead of the raw `<span className="rounded-full bg-[var(--surface-2)] ...">` markup, keeping the same information (type name, priority name, publish date).

### 6.2 AnnouncementFeedPage.tsx (`FeedCard`)

- [ ] **6.2.1** Migrate `FeedCard`'s hand-rolled `<button>` block to shadcn `Card` + `Badge`, preserving: priority badge, "Nuevo" unread indicator, title, `line-clamp-3` preview of **plain** `content` (never `content_html`), attachment count, publish date.
- [ ] **6.2.2** Verify `AnnouncementListPage.tsx`'s row preview (`<span className='... truncate ...'>{announcement.content}</span>`) is untouched — it already uses plain `content` and is explicitly out of scope for the redesign.

### 6.3 Frontend tests

- [ ] **6.3.1** `AnnouncementReadDialog` render test: with `content_html` present, sanitized HTML renders; with `content_html` null, falls back to plain `content`.
- [ ] **6.3.2** `FeedCard` render smoke test: badge/unread indicator/preview render as before, now via `Card`/`Badge`.

---

## WU-7 — Verify + quality gates

### 7.1 Backend

- [ ] **7.1.1** `vendor/bin/pint --dirty` (from `backend/`) — zero formatting errors.
- [ ] **7.1.2** `php artisan test --filter=AnnouncementRichContentTest` — green.
- [ ] **7.1.3** `php artisan test --filter=AnnouncementReadStatsControllerTest` — green.
- [ ] **7.1.4** `php artisan test --filter=Announcement` (full module regression, one process at a time on the shared `testing` DB) — green.

### 7.2 Frontend

- [ ] **7.2.1** `pnpm lint` — zero ESLint errors, zero `any`.
- [ ] **7.2.2** `pnpm build` — `tsc -b && vite build` succeeds.
- [ ] **7.2.3** `pnpm test` — all Vitest tests green (schemas, resources, render smokes from WU-3 to WU-6).

### 7.3 Manual QA (LAN IP, per project convention — NOT `localhost`)

- [ ] **7.3.1** From `/announcement/new`: author with bold + list + link, save as draft, publish.
- [ ] **7.3.2** Open as resident: reader renders formatted HTML; feed card looks "pro"; the push/email notification excerpt is plain text.
- [ ] **7.3.3** `/announcement/:id/edit` → "Lecturas" tab: verify counts, unit→resident expansion, `read_at`, filters (Todos/Leídos/No leídos), search, pagination if the seeded data spans >1 page.

### 7.4 Review (recommended, not blocking)

- [ ] **7.4.1** `/review-stack` — expected reviewers: `laravel-architecture-reviewer` (service/controller placement, `$fillable` guard), `react-frontend-reviewer` (new page/components, no `any`), `integration-reviewer` (payload contract for `read-stats`, `content_html` field), `tenancy-reviewer` (read-stats tenant scope via `BelongsToTenant` on `Announcement`/`AnnouncementRead`/`UnitUser`).
- [ ] **7.4.2** `@infra-cost-reviewer` — confirm the "Lecturas" tab never fetches before it is opened, and that `AnnouncementListPage` triggers zero read-stats calls.

---

## Spec → Task traceability matrix

| Spec requirement | Satisfied by |
|---|---|
| R-RT-1 (dual field, server-derived plain text) | WU-1.2, WU-1.3, WU-1.4, WU-1.5 |
| R-RT-2 (sanitization strips unsafe markup) | WU-1.1, WU-1.5, WU-1.7 |
| R-RT-3 (`content_html` not mass-assignable) | WU-1.3, WU-1.7 |
| R-RT-4 (author UI: TipTap, shared component) | WU-3, WU-4.3 |
| R-RT-5 (reader renders sanitized HTML, plain-text fallback) | WU-3.1.2, WU-6.1 |
| R-RT-6 (previews/notifications stay plain-text) | WU-1.5, WU-6.2.2 |
| R-STATS-1 (live audience resolution, no snapshot) | WU-2.2 |
| R-STATS-2 (counts at unit + person level) | WU-2.2, WU-2.3 |
| R-STATS-3 (per-unit → per-resident breakdown with `read_at`) | WU-2.2, WU-5.3 |
| R-STATS-4 (board target has no unit grouping) | WU-2.2 (`boardShape`), WU-5.3.5 |
| R-STATS-5 (endpoint gated to managers) | WU-2.3 |
| R-STATS-6 (paginated by unit, filters server-side) | WU-2.2, WU-2.4, WU-5.3 |
| R-STATS-7 (lazy-loaded, only on tab open) | WU-4.4.2, WU-5.3.2, WU-7.4.2 |
| R-PAGE-1 (Sheet → dedicated page with tabs) | WU-4.4 |
| R-PAGE-2 (routes gated by existing permission) | WU-4.5.1 |

---

## Review Workload Forecast

| Metric | Estimate |
|---|---|
| Backend new/changed files | ~9 (1 shared sanitizer + 1 migration + 1 new service + model/requests/service/controller edits + 1 route edit + 2 test files) |
| Frontend new/changed files | ~11 (2 shared richtext components + `AnnouncementForm` + `AnnouncementEditorPage` + `AnnouncementReadStats` + schema/resource edits + `AnnouncementReadDialog`/`FeedCard`/`AnnouncementListPage`/`App.tsx` edits + `AnnouncementComposeModal` removed) |
| Estimated changed lines | ~500–700 |
| Chained PRs recommended | No — single cohesive change, cross-stack but bounded to one module |
| Review | `/review-stack` + `@infra-cost-reviewer` recommended before merge (not a hard gate per this document, but per `CLAUDE.md`'s pre-PR trigger rules for cross-stack changes) |

---

*Generated in a fast-forward pass (proposal + spec + design + tasks) on 2026-07-04, from the approved session plan.*
