# SDD Design — Anuncios: rich text, página dedicada y estadísticas de lectura

- **Change:** `announcements-richtext-read-stats`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** design (the HOW at architectural level)
- **Artifact store:** hybrid
- **Date:** 2026-07-04
- **Reads:** proposal.md (this change), the approved session plan, `Modules/Chat` (`ChatHtmlSanitizer`, `MessageComposer.tsx`, `ChatMessageBubble.tsx`), `Modules/Announcements` (model, controller, requests, service, migrations), `frontend/src/modules/announcement/*`.
- **Authority order on conflict:** laravel-backend-architecture / stancl-tenancy / react-frontend-architecture skills > `CLAUDE.md` > this design.

All decisions below were closed in the approved plan; this document grounds them in the live codebase (every file referenced was read this phase) and fixes the exact contracts left open by the plan.

---

## 0. Executive summary

Two independent slices, both additive to `Modules/Announcements`:

1. **Rich text.** A new nullable `content_html` column stores sanitized HTML; `content` (existing, plain text) is now derived server-side from it. Sanitization is a new shared `App\Services\RichTextSanitizer`, lifted verbatim from `Modules\Chat\Services\ChatHtmlSanitizer`'s allowlist (which already permits links). Frontend gets two new shared components, `RichTextEditor` (TipTap) and `RichTextContent` (DOMPurify), generalizing Chat's `MessageComposer`/`ChatMessageBubble`.
2. **Read statistics.** A new `GET /announcement/{id}/read-stats` endpoint + `AnnouncementReadStatsService`, computed live by reusing the existing `AnnouncementService::audienceUserIds()` resolver (the same one behind `POST /announcement/preview`) crossed with `announcement_read`.

Alongside these, alta/edición moves from a Sheet (`AnnouncementComposeModal`) to a page (`AnnouncementEditorPage`) with a "Contenido" / "Lecturas" tab split, and the reader/feed get a shadcn-primitive redesign.

---

## 1. Architecture approach

### 1.1 No new patterns — this rides two existing, proven seams

- **Rich text storage & sanitization** = the `Modules\Chat` `body`/`body_html` seam: dual column, sanitize-then-derive in a Service, never trust client HTML, exclude the HTML field from `$fillable`.
- **Read-stats aggregation** = the `PaymentController::delinquency` / `AnnouncementController::preview` seam: a custom (non-Orion) route → controller authorizes + delegates to a Service → `response()->json([...])`. The "resolve audience, then aggregate" shape already exists in `AnnouncementService::audienceUserIds()`; read-stats reuses it rather than re-deriving the target resolution logic.

### 1.2 Module boundaries (who owns what)

| Concern | Owner | New/changed file(s) |
|---|---|---|
| Shared HTML sanitizer | **App (root)** | `app/Services/RichTextSanitizer.php` (new) |
| `content_html` column | **Announcements** | migration (new) |
| Sanitize-on-write, plain-text derivation | **Announcements** | `AnnouncementService.php` (changed: `applyRichContent()`) |
| Create/update wiring | **Announcements** | `AnnouncementController.php` (changed: `performStore`), `AnnouncementCreateRequest.php`, `AnnouncementUpdateRequest.php` (changed) |
| Read-stats aggregation | **Announcements** | `AnnouncementReadStatsService.php` (new) |
| Read-stats endpoint | **Announcements** | `AnnouncementController.php` (changed: `readStats()`), `routes/api.php` (changed) |
| Rich-text author/render atoms | **frontend/shared** | `shared/components/richtext/RichTextEditor.tsx`, `RichTextContent.tsx` (new) |
| Page migration + tabs | **frontend/announcement** | `AnnouncementEditorPage.tsx` (new), `components/AnnouncementForm.tsx` (new, extracted), `AnnouncementComposeModal.tsx` (removed), `AnnouncementListPage.tsx` (changed), `App.tsx` (changed) |
| Read-stats panel | **frontend/announcement** | `components/AnnouncementReadStats.tsx` (new), `services/AnnouncementResource.ts` (changed), `schema.ts` (changed) |
| "Pro" redesign | **frontend/announcement** | `components/AnnouncementReadDialog.tsx` (changed), `AnnouncementFeedPage.tsx` (changed) |

### 1.3 Data-flow — authoring

```
[AnnouncementForm] --RichTextEditor(TipTap)--> content_html (client HTML, UNTRUSTED)
   --submit--> POST/PATCH /announcement[/:id] --> AnnouncementCreateRequest/UpdateRequest
      (content_html: required string; content: nullable, ignored if sent)
   --> Controller (performStore) / AnnouncementService::update()
      --> AnnouncementService::applyRichContent($entity, $html):
            $clean = RichTextSanitizer::sanitize($html)   // HTMLPurifier allowlist
            $entity->content_html = $clean                 // direct attribute set — bypasses $fillable
            $entity->content = RichTextSanitizer::toPlainText($clean)
   --> $entity->save()
<- Announcement { content, content_html } -> RichTextContent (DOMPurify) on read surfaces
```

### 1.4 Data-flow — read stats

```
[AnnouncementReadStats panel] --useQuery (enabled: "Lecturas" tab active)--> GET /announcement/{id}/read-stats?page&per_page&status&search
   -> Controller: authorize('update.announcement') -> AnnouncementReadStatsService::build(...)
      -> AnnouncementService::audienceUserIds($announcement)   // REUSED, unchanged
      -> AnnouncementRead::where(announcement_id)->whereIn(user_id, recipients)  // who already read
      -> group by unit (via UnitUser) OR flat list (board target)
      -> paginate by unit, apply status/search filters
   -> response()->json({...})
<- typed payload -> Zod schema -> grouped-by-unit expandable list
```

---

## 2. Backend — rich text

### 2.1 Migration

New file `backend/Modules/Announcements/database/migrations/2026_07_04_090000_add_content_html_to_announcement_table.php`:

```php
Schema::table('announcement', function (Blueprint $table): void {
    $table->text('content_html')->nullable()->after('content')
        ->comment('Sanitized rich-text HTML for display; content holds the plain-text derivation used for search + notification excerpts.');
});
```

Nullable because existing rows have no HTML — `RichTextContent`/`AnnouncementReadDialog` fall back to plain `content` when it is null (§5.3).

### 2.2 Shared sanitizer — `App\Services\RichTextSanitizer`

New `backend/app/Services/RichTextSanitizer.php`, copying `Modules\Chat\Services\ChatHtmlSanitizer`'s `CONFIG`, `sanitize()`, and `toPlainText()` **verbatim** (same allowlist: `p,br,b,strong,i,em,u,span[style],ul,ol,li,a[href]`; `color,font-size`; `http/https/mailto`). `ChatHtmlSanitizer` is **not** modified or removed — migrating Chat onto the shared class is explicitly out of scope (proposal §2). The allowlist already includes `a[href]`, which happens to be exactly what the announcement editor's "link" button needs — no allowlist changes required.

### 2.3 Model — `Announcement.php`

- `content_html` is **NOT** added to `$fillable`. It is set only via direct attribute assignment inside `AnnouncementService::applyRichContent()` (Eloquent's mass-assignment guard applies to `fill()`/`create()` only, not to `$model->content_html = $value`).
- No cast needed (plain string/text column).

### 2.4 Requests

`AnnouncementCreateRequest::rules()`:
```php
'content' => ['nullable', 'string'],                      // was 'required'; now server-derived
'content_html' => ['required', 'string', 'max:20000'],     // new
```

`AnnouncementUpdateRequest::rules()`:
```php
'content' => ['sometimes', 'nullable', 'string'],
'content_html' => ['sometimes', 'required', 'string', 'max:20000'],
```

Array style kept (matches the file's existing convention). `content` stays in the validated set only so a legacy/API caller sending it doesn't 422 — the value is always discarded server-side in favor of the derivation.

### 2.5 Service — `AnnouncementService.php`

New **public** method (public so the controller can call it from `performStore` — see §2.6):

```php
/**
 * Sanitize author-supplied rich-text HTML and derive the plain-text mirror.
 * content_html is set directly (bypassing $fillable by design — see Announcement model).
 */
public function applyRichContent(Announcement $announcement, string $html): void
{
    $clean = RichTextSanitizer::sanitize($html);
    $announcement->content_html = $clean;
    $announcement->content = RichTextSanitizer::toPlainText($clean);
}
```

`update()` changes to extract `content_html` **before** `fill()` (so `content`/`content_html` never reach `fill()` with a stale/untrusted value):

```php
public function update(Announcement $announcement, array $attributes): Announcement
{
    $wasPublished = $announcement->announcement_status_id === AnnouncementStatus::PUBLISHED;

    if (array_key_exists('content_html', $attributes)) {
        $this->applyRichContent($announcement, $attributes['content_html']);
        unset($attributes['content_html'], $attributes['content']);
    }

    $announcement->fill($attributes);
    $visibleContentChanged = $announcement->isDirty(['title', 'content']);
    // ...unchanged from here (edited_at stamping, notifyReadersOfEdit)
}
```

Notification excerpts (`dispatchPublishedNotifications`, `notifyReadersOfEdit`) already read `strip_tags($announcement->content)` — since `content` is now already plain text, `strip_tags` becomes a harmless no-op. **No change needed there.**

### 2.6 Controller — `AnnouncementController::performStore`

```php
protected function performStore(OrionRequest $request, Model $entity, array $attributes): void
{
    $data = $request->validated();
    unset($data['announcement_status_id'], $data['author_user_id'], $data['edited_at']);

    $contentHtml = $data['content_html'] ?? null;
    unset($data['content_html'], $data['content']);

    $entity->fill($data);
    $entity->announcement_status_id = AnnouncementStatus::DRAFT;
    $entity->author_user_id = auth()->guard('sanctum')->id();

    if ($contentHtml !== null) {
        app(AnnouncementService::class)->applyRichContent($entity, $contentHtml);
    }

    $entity->save();
}
```

`searchableBy()` stays `['title', 'content']` — unchanged, still plain text.

---

## 3. Frontend — shared rich text components

New directory `frontend/src/shared/components/richtext/`.

### 3.1 `RichTextEditor.tsx` (author)

Generalizes `modules/chat/components/MessageComposer.tsx`'s TipTap setup:

- Extensions: `StarterKit` (bundles Bold, Italic, Underline, bullet/ordered lists, and — **verified against upstream TipTap v3 docs** — the `Link` mark; no `@tiptap/extension-link` package needed).
- Toolbar: Negrita, Cursiva, Subrayado, Lista con viñetas, Lista numerada, Enlace (prompts for a URL, calls `editor.chain().focus().setLink({ href }).run()`; a second click with an active link calls `unsetLink()`).
- API: controlled `value: string` (HTML) / `onChange: (html: string) => void`, wired via React Hook Form's `Controller` exactly like any other controlled field — NOT the imperative `editor.getHTML()`-on-send pattern `MessageComposer` uses (that pattern fits chat's "compose then clear"; the announcement form is a persisted, edit-in-place field).
- No image/color/font-size toolbar (out of scope for this editor; those stay Chat-only).

### 3.2 `RichTextContent.tsx` (render)

Generalizes `modules/chat/components/ChatMessageBubble.tsx`'s `MessageBody`:

```tsx
interface RichTextContentProps {
  html?: string | null
  /** Rendered when html is null/empty (e.g. legacy rows with no content_html yet). */
  fallbackText?: string | null
  className?: string
}
```

- Same `DOMPurify` config as `ChatMessageBubble` (`ALLOWED_TAGS: ['b','strong','i','em','u','p','br','ul','ol','li','span','a']`, `ALLOWED_ATTR: ['style','href','target','rel']`, `FORCE_BODY: true`) — mirrors the backend allowlist exactly.
- When `html` is present: `dangerouslySetInnerHTML` with the sanitized string, plus a few scoped `[&_ul]`/`[&_ol]`/`[&_p]`/`[&_a]` Tailwind arbitrary-variant rules for spacing/link color (no `@tailwindcss/typography` dependency, per the plan).
- When `html` is null/empty: renders `fallbackText` as `whitespace-pre-wrap` plain text (covers pre-migration announcements with no `content_html`).

---

## 4. Backend — read statistics

### 4.1 `AnnouncementReadStatsService`

New `backend/Modules/Announcements/app/Services/AnnouncementReadStatsService.php`:

```php
class AnnouncementReadStatsService
{
    public function __construct(private readonly AnnouncementService $announcementService) {}

    public function build(Announcement $announcement, int $page, int $perPage, string $status, ?string $search): array
    {
        $recipientIds = $this->announcementService->audienceUserIds($announcement); // REUSED — same resolver as preview
        $targetPeopleCount = count($recipientIds);

        $readAtByUser = AnnouncementRead::where('announcement_id', $announcement->id)
            ->whereIn('user_id', $recipientIds)
            ->pluck('read_at', 'user_id');

        $readPeopleCount = $readAtByUser->count();
        $unreadPeopleCount = $targetPeopleCount - $readPeopleCount;
        $readPercentage = $targetPeopleCount > 0 ? (int) round($readPeopleCount / $targetPeopleCount * 100) : 0;

        $base = [
            'target_type' => $announcement->target_type,
            'target_people_count' => $targetPeopleCount,
            'read_people_count' => $readPeopleCount,
            'unread_people_count' => $unreadPeopleCount,
            'read_percentage' => $readPercentage,
        ];

        if ($announcement->target_type === 'board') {
            return $base + $this->boardShape($recipientIds, $readAtByUser, $search);
        }

        return $base + $this->unitGroupedShape($recipientIds, $readAtByUser, $page, $perPage, $status, $search);
    }
    // private boardShape(...) and unitGroupedShape(...) below
}
```

**`boardShape()`** — 'board' targets resolve to board-member user ids with no unit ownership context (§ risk in proposal.md):
- `target_units_count => null` (not applicable — never `0`, to avoid reading as "zero units reached").
- `units => []`.
- `people => User::whereIn('id', $recipientIds)->withoutGlobalScope(UserTenantScope::class)->orderBy('name')->get(['id','name'])`, mapped to `{ user_id, name, read: bool, read_at }` using `$readAtByUser`. `$search` filters by name (case-insensitive `LIKE`) when present; no pagination (board is a small, bounded set by construction).

**`unitGroupedShape()`** — every other target type:
1. `UnitUser::whereIn('user_id', $recipientIds)->whereNull('end_date')->with(['unit', 'user'])->get()->groupBy('unit_id')` — one query, no N+1 (mirrors the `Collections\CollectionsReportService`-style "load once, group in PHP" precedent used elsewhere in this repo for bounded sets).
2. `target_units_count = $grouped->count()` (units that actually have ≥1 active recipient — may be lower than the raw target-unit count if a targeted unit currently has no active resident, which is correct: it has nobody to read the announcement).
3. **Filtering (server-side, before pagination — see §4.2 for why):**
   - `search`: keep a unit if its `unit->full_identifier` matches, OR any of its people's `user->name` matches (case-insensitive).
   - `status = 'read' | 'unread'`: within each surviving unit, keep only the people matching that read state for the `people[]` array; a unit is dropped from the page entirely if it has zero matching people. `read_count`/`total_count` on a unit always reflect the **full** unit (not the filtered subset), so the "2/2 leyeron" header stays meaningful even when the list below is narrowed to "solo no leídos".
   - `status = 'all'` (default): all people shown, no unit dropped by status.
4. **Pagination:** `forPage($page, $perPage)` over the filtered, grouped-by-unit collection; sort units by `unit->full_identifier` for a stable order across pages.
5. Each unit → `{ unit_id, unit_label: unit->full_identifier, read_count, total_count, people: [{ user_id, name, read, read_at }] }`, people sorted by name.
6. `meta => { current_page, per_page, total_units: <post-filter count>, last_page }`.

### 4.2 Why search/status are query params, not client-side filters

Pagination is server-side (by unit) specifically so a cluster with `target_type = 'all'` and hundreds of units never loads its whole audience in one response (the project's stated infra-cost goal). If `search`/`status` were applied only within the currently-loaded page, "3 de 40 sin leer" would be wrong once page 2 exists. Both are therefore endpoint query params, applied before pagination, exactly like any other server-paginated list in this app.

### 4.3 Endpoint contract — `GET /announcement/{id}/read-stats`

Query params: `page` (int, ≥1, default 1), `per_page` (int, 1–100, default 20), `status` (`all`|`read`|`unread`, default `all`), `search` (string, nullable, max 255).

**Unit-scoped target** (e.g. `by_tower` reaching 2 units / 3 residents, 1 already read):

```json
{
  "target_type": "by_tower",
  "target_units_count": 2,
  "target_people_count": 3,
  "read_people_count": 1,
  "unread_people_count": 2,
  "read_percentage": 33,
  "units": [
    {
      "unit_id": "uuid",
      "unit_label": "Torre Norte · Depto 101",
      "read_count": 1,
      "total_count": 2,
      "people": [
        { "user_id": "uuid", "name": "Juan Pérez", "read": true, "read_at": "2026-07-01T15:04:00-06:00" },
        { "user_id": "uuid", "name": "Ana Ruiz", "read": false, "read_at": null }
      ]
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total_units": 2, "last_page": 1 }
}
```

**Board target** (no unit grouping):

```json
{
  "target_type": "board",
  "target_units_count": null,
  "target_people_count": 5,
  "read_people_count": 5,
  "unread_people_count": 0,
  "read_percentage": 100,
  "units": [],
  "people": [
    { "user_id": "uuid", "name": "María López", "read": true, "read_at": "2026-07-02T09:00:00-06:00" }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total_units": 0, "last_page": 1 }
}
```

Bare JSON object (no `{data: ...}` wrapper) — matches the project's convention for report/aggregation endpoints (e.g. `preview`'s bare `{count}`), distinct from the Orion-wrapped CRUD envelope.

### 4.4 Controller — `AnnouncementController::readStats`

```php
public function readStats(string $id, Request $request): JsonResponse
{
    $this->authorize('update.announcement', Announcement::class);

    $announcement = Announcement::findOrFail($id);

    $validated = $request->validate([
        'page' => ['sometimes', 'integer', 'min:1'],
        'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        'status' => ['sometimes', Rule::in(['all', 'read', 'unread'])],
        'search' => ['sometimes', 'nullable', 'string', 'max:255'],
    ]);

    $stats = app(AnnouncementReadStatsService::class)->build(
        $announcement,
        page: (int) ($validated['page'] ?? 1),
        perPage: (int) ($validated['per_page'] ?? 20),
        status: $validated['status'] ?? 'all',
        search: $validated['search'] ?? null,
    );

    return response()->json($stats);
}
```

**Gate: `update.announcement`, not `read.announcement`.** This is deliberately stricter than the existing `readMetrics` action (which uses `read.announcement`) — the same reasoning `preview` already applies (`create.announcement`, not `read`): a plain resident must not be able to enumerate per-unit/per-resident read state for an announcement. Reconciling `readMetrics`'s looser gate is out of scope for this change.

### 4.5 Route

`backend/Modules/Announcements/routes/api.php`, inside the existing `$customMiddleware` group (same group as `publish`/`archive`/`read`/`metrics` — registered **after** the Orion resource, which is safe here because `read-stats` has an extra path segment and cannot be mis-parsed as the resource's `show` route, unlike the `board`/`preview` literal-path routes that had to be registered *before* the resource):

```php
Route::get('announcement/{id}/read-stats', [AnnouncementController::class, 'readStats']);
```

---

## 5. Frontend — page migration, read-stats panel, redesign

### 5.1 Schema changes (`frontend/src/modules/announcement/schema.ts`)

- `IAnnouncement`: add `content_html?: string | null`.
- `announcementFormSchema`: replace the `content: z.string().min(1, ...)` field with `content_html: z.string().min(1, 'El contenido es requerido')`. `buildAnnouncementFormDefaults` hydrates from `announcement?.content_html ?? ''`.
- New read-stats types:
  ```ts
  export const readStatsPersonSchema = z.object({
    user_id: z.string(),
    name: z.string(),
    read: z.boolean(),
    read_at: z.string().nullable(),
  })
  export const readStatsUnitSchema = z.object({
    unit_id: z.string(),
    unit_label: z.string(),
    read_count: z.number().int(),
    total_count: z.number().int(),
    people: z.array(readStatsPersonSchema),
  })
  export const announcementReadStatsSchema = z.object({
    target_type: z.string(),
    target_units_count: z.number().int().nullable(),
    target_people_count: z.number().int(),
    read_people_count: z.number().int(),
    unread_people_count: z.number().int(),
    read_percentage: z.number().int().min(0).max(100),
    units: z.array(readStatsUnitSchema),
    people: z.array(readStatsPersonSchema).optional(), // present only for target_type = 'board'
    meta: z.object({
      current_page: z.number().int(),
      per_page: z.number().int(),
      total_units: z.number().int(),
      last_page: z.number().int(),
    }),
  })
  export type IAnnouncementReadStats = z.infer<typeof announcementReadStatsSchema>
  ```
  No `any`.

### 5.2 Resource (`services/AnnouncementResource.ts`)

Add a `readStats` custom method:
```ts
readStats: (id: string, params: {
  page?: number; per_page?: number; status?: 'all' | 'read' | 'unread'; search?: string
}) => Promise<IAnnouncementReadStats>
```
implemented as `apiClient.get<IAnnouncementReadStats>(`/announcement/${id}/read-stats`, { params }).then(r => r.data)` (flat payload, no `data` wrapper — matches the backend contract in §4.3).

### 5.3 `AnnouncementForm.tsx` (new — extracted from `AnnouncementComposeModal`)

Everything currently inside `AnnouncementComposeModal`'s `<SheetContent>` body (title, type/priority selects, expires date, `SingleTargetSelector`, channel checkboxes, attachments), **minus the `Sheet` chrome**, plus:
- The content `<Textarea>` replaced by `<RichTextEditor>` bound via `Controller` on `content_html`.
- Same `useMutation` create/update logic (payload now sends `content_html` instead of `content`), same attachment upload flow.
- Props: `{ mode, announcement?, onSaved }` (no `isOpen`/`onClose` — the page, not a Sheet, owns visibility).

`AnnouncementComposeModal.tsx` is deleted once `AnnouncementListPage` stops using it (§5.5).

### 5.4 `AnnouncementEditorPage.tsx` (new)

```
frontend/src/modules/announcement/AnnouncementEditorPage.tsx
```
- Reads `:id` from the route (`useParams<{ id?: string }>()`). No `id` → create mode; `id` present → edit mode, loads the announcement via `AnnouncementResource.find(id)` (existing `createCrudApi` method).
- shadcn `Tabs`: **"Contenido"** (always) → `<AnnouncementForm mode={...} announcement={...} onSaved={...} />`; **"Lecturas"** (edit mode only, disabled/hidden in create mode — a draft that doesn't exist yet has no readers) → `<AnnouncementReadStats announcementId={id} />`.
- On successful create, navigate to `/announcement/:newId/edit` (so "Lecturas" becomes reachable) or back to `/announcement` — follow the existing modal's post-create UX (stays on the record, per the modal's current "guardado como borrador" toast flow — apply verifies against the removed modal's `onSaved` callback wiring).

### 5.5 Routing (`App.tsx`) + list reconnection

```tsx
const AnnouncementEditorPage = lazy(() => import('@/modules/announcement/AnnouncementEditorPage').then(m => ({ default: m.AnnouncementEditorPage })))
```
```tsx
<Route element={<PermissionRoute permissionEntity="announcement" />}>
  <Route path="/announcement" element={<AnnouncementsPage />} />
  <Route path="/announcement/new" element={<AnnouncementEditorPage />} />
  <Route path="/announcement/:id/edit" element={<AnnouncementEditorPage />} />
</Route>
```
Same `permissionEntity="announcement"` as the existing route — no new permission entity needed (mirrors the plan: "Permisos backend ya existen").

`AnnouncementListPage.tsx`: "Nuevo aviso" button → `navigate('/announcement/new')` instead of opening `composeOpen`; the row "Editar" action → `navigate(`/announcement/${announcement.id}/edit`)`. The `{composeOpen && <AnnouncementComposeModal .../>}` block and its state (`composeOpen`, `modalMode`, `selectedAnnouncement`) are removed.

### 5.6 `AnnouncementReadStats.tsx` (new)

```
frontend/src/modules/announcement/components/AnnouncementReadStats.tsx
```
- Props: `{ announcementId: string }`. Local state: `page`, `status` ('all'|'read'|'unread'), `search` (debounced).
- `useQuery({ queryKey: ['announcement-read-stats', announcementId, page, status, search], queryFn: () => AnnouncementResource.readStats(announcementId, { page, status, search }), enabled: <tab is active> })` — the `enabled` gate is the lazy-load requirement (never fetches until the "Lecturas" tab is opened; re-fetches on filter/page change only while active). This is the same load-on-open discipline `useCatalogs`/`useLazyCatalogs` already enforce elsewhere in the app (`CLAUDE.md` §4, eager-loading anti-pattern) — worth an `@infra-cost-reviewer` pass.
- Header: "**X de Y personas** leyeron (Z%)" + "**N unidades** alcanzadas" (or omitted when `target_units_count` is `null`, i.e. a `board` target).
- Filter control: "Todos / Leídos / No leídos" (maps to `status`) + a search input (unit/name) — both drive the query params in §4.1/§4.2, not client-side filtering.
- List: grouped by unit ("Torre Norte · Depto 101 — 1/2 leyeron"), expandable to the per-person rows (name, read/unread chip, `displayFormatDate(read_at)` when read). For `board` targets, render the flat `people[]` directly (no unit header).
- Pagination via the existing `Pagination` component driven by `meta`.
- Loading: `boneyard-js` `<Skeleton loading={isLoading}>` (never `animate-pulse`). Empty: "Este aviso aún no tiene destinatarios" when `target_people_count === 0`.

### 5.7 "Pro" redesign

- `AnnouncementReadDialog.tsx`: replace `<p className="whitespace-pre-wrap">{content}</p>` with `<RichTextContent html={announcement?.content_html} fallbackText={announcement?.content} />`; keep the existing type/priority/date chip row, restyled with `Badge`/`StatusPill` instead of raw `<span>` pills.
- `AnnouncementFeedPage.tsx`'s `FeedCard`: migrate the hand-rolled `<button>` block to shadcn `Card` + `Badge` primitives, same visual information (priority badge, "Nuevo" indicator, title, `line-clamp-3` **plain** `content` preview, attachment count, publish date). The preview stays plain-text — `content`, never `content_html` — consistent with the list's row preview in `AnnouncementListPage`, which is untouched.

---

## 6. Testing approach

- **Backend (PHPUnit feature tests, `Modules/Announcements/tests/Feature/`):**
  - `AnnouncementRichContentTest.php`: creating/updating with `content_html` containing `<script>alert(1)</script><strong>hola</strong>` persists `content_html` without `<script>` but with `<strong>`, and `content` equals the derived plain text ("hola"). `content_html` absent from a raw mass-assignment attempt (`Announcement::create([...'content_html'=>'<script>...</script>'])` in a unit test) is silently ignored by `$fillable`.
  - `AnnouncementReadStatsControllerTest.php`: seed an announcement with a `by_tower`/`by_type` target reaching 2 units / 3 people, mark 1 read → asserts `target_units_count=2`, `target_people_count=3`, `read_people_count=1`, `unread_people_count=2`, `read_percentage=33`, per-unit `read_count`/`total_count`, per-person `read`/`read_at`. Separate case for `target_type='board'` asserting `units=[]` + flat `people[]` + `target_units_count=null`. Permission gate: `read.announcement`-only user → 403; `update.announcement` user → 200. Tenant isolation: tenant B's `announcement_read` rows never leak into tenant A's stats.
- **Frontend (Vitest + Testing Library):** schema tests for `announcementReadStatsSchema` (board vs unit-grouped payload), a `RichTextContent` render test (script tag stripped, `<strong>` kept, fallback path when `html` is null), a `readStats()` resource test (asserts query params reach the URL), and a render smoke test for `AnnouncementReadStats` (loading/empty/populated/board-shape).
- **Quality gates:** `vendor/bin/pint --dirty`, targeted `php artisan test --filter=Announcement`, `pnpm lint`, `pnpm build`, `pnpm test`. Recommended (not blocking): `/review-stack` (laravel-architecture, react-frontend, integration, tenancy) and `@infra-cost-reviewer` for the read-stats lazy-loading contract.

---

## 7. Open items / assumptions for apply (not blockers)

- **Post-create navigation target** in `AnnouncementEditorPage` (§5.4) — apply should read the removed modal's exact `onSaved`/toast sequence before deciding stay-on-page vs. redirect-to-list, to preserve today's UX.
- **`content_html` max length (20000)** — matches `content`'s implicit bound in `AnnouncementCreateRequest` today (no explicit max was set on `content` before; 20000 is a new, deliberate cap on the richer field to bound sanitizer cost and storage — apply may adjust after checking `AnnouncementService::MAX_ATTACHMENTS`-style precedent for a project-wide "max content" convention, if one exists elsewhere).
- **`AnnouncementFeedPage`/`AnnouncementReadDialog` styling tokens** (colors, spacing) — apply should match the existing `--ink-*`/`--green*` CSS custom properties already used throughout `modules/announcement/*`, not introduce new design tokens.

---

## 8. ADR-style decision log

### ADR-1: Dual field, server-derived plain text (not client-authored plain text)
- **Status:** Accepted.
- **Context:** Need rich content while keeping `searchableBy` and notification excerpts on plain text.
- **Decision:** `content_html` is the authored/sanitized field; `content` is derived server-side via `RichTextSanitizer::toPlainText()` on every write.
- **Consequences:** Search and excerpts need zero changes. The client never sends a meaningful `content` (kept nullable/sometimes for backward compatibility, always overwritten).
- **Rejected:** Deriving plain text client-side and sending both — reintroduces the "trust the client" risk this change exists to close.

### ADR-2: Shared `RichTextSanitizer` extracted, `ChatHtmlSanitizer` untouched
- **Status:** Accepted.
- **Context:** Chat already proves this exact sanitize/derive pattern; duplicating the allowlist inline in Announcements would drift over time.
- **Decision:** Extract to `App\Services\RichTextSanitizer` with the identical allowlist; Chat keeps using its own class unchanged.
- **Consequences:** One canonical allowlist for future modules to adopt; zero regression risk to Chat (it isn't touched). Migrating Chat onto the shared class is explicitly deferred.

### ADR-3: Read-stats computed live from `audienceUserIds()`, no snapshot table
- **Status:** Accepted.
- **Context:** The audience of a target can shift (residents move in/out); the plan explicitly rejects a materialized snapshot.
- **Decision:** Reuse `AnnouncementService::audienceUserIds()` (already the audience resolver for `preview`) on every `read-stats` request.
- **Consequences:** No new table, no background job, no staleness. Cost is one extra resolve-audience call per stats view — acceptable given the endpoint is lazy-gated (only fetched when the "Lecturas" tab opens) and paginated.
- **Rejected:** A `announcement_target_snapshot` table populated at publish time — adds write-path complexity and a staleness bug class (a resident who moves out after publish would still count) for a report that isn't otherwise consistency-critical.

### ADR-4: `read-stats` gated by `update.announcement`, not `read.announcement`
- **Status:** Accepted.
- **Context:** `readMetrics` (existing) uses `read.announcement`, which residents hold. `read-stats` exposes per-unit/per-resident identities, a materially more sensitive surface.
- **Decision:** Gate `read-stats` on `update.announcement`, the same ability `preview` already requires for audience-shaped data.
- **Consequences:** Only managers/authors-with-manage-rights can see who-read-what. `readMetrics`'s looser gate is a pre-existing inconsistency, left alone (out of scope).

### ADR-5: Filter/search on `read-stats` are server-side query params
- **Status:** Accepted.
- **Context:** Pagination is server-side by unit (infra-cost discipline for large `target_type='all'` audiences); client-side filtering over one loaded page would misreport totals once page 2+ exists.
- **Decision:** `status` and `search` are query params applied before pagination; per-unit `read_count`/`total_count` always reflect the full unit regardless of the active filter.
- **Consequences:** Consistent counts across pages and filters; slightly more service-side branching (documented in §4.1) but bounded and testable.
