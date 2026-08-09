# Announcements — Spec Delta

This delta adds rich-text authoring/rendering and per-unit/per-resident read statistics to the existing Announcements capability (see `openspec/changes/module-8-announcements/spec.md` for the base capability this builds on: entity shape, publish/archive lifecycle, read tracking, board endpoint, push dispatch). Nothing below removes or contradicts that base spec — `content` (plain text), `announcement_read`, `publish()`/`archive()`/`markRead()`, and the board endpoint's expiry filter are unchanged in behavior.

## MODIFIED Requirements

### Requirement: Announcement content storage (R-RT-1)

An `announcement` MUST store its content in two fields: `content_html` (sanitized rich-text HTML, the field an author edits) and `content` (plain text, SERVER-DERIVED from `content_html` on every write). The system MUST NOT accept a client-authored `content` value as the source of truth — it is always overwritten by the server-side derivation. `content_html` MAY be null for announcements created before this change; `content` remains populated for those (unchanged, pre-existing plain text).

#### Scenario: Creating an announcement derives plain text from HTML

- GIVEN an author submits `content_html = "<p>Corte de <strong>agua</strong> programado</p>"`
- WHEN the announcement is created
- THEN the stored `content_html` is the sanitized HTML (see sanitization requirement below)
- AND the stored `content` equals the plain-text derivation, `"Corte de agua programado"`

#### Scenario: A pre-existing announcement with no content_html still renders

- GIVEN an announcement created before this change has `content = "Corte de agua"` and `content_html = null`
- WHEN the announcement is read
- THEN the entity carries `content_html: null` and the unchanged plain `content`
- AND no error occurs — reading surfaces fall back to plain text (see rendering requirement below)

---

## ADDED Requirements

### Requirement: Server-side sanitization of authored HTML (R-RT-2)

Every `content_html` value MUST be sanitized server-side, via a shared sanitizer (HTMLPurifier-based, strict allowlist), before being persisted. The allowlist permits only: `p, br, b, strong, i, em, u, span[style], ul, ol, li, a[href]`, the `color`/`font-size` CSS properties, and `http`/`https`/`mailto` link schemes. Scripts, event-handler attributes, `javascript:` URIs, and any tag/attribute outside the allowlist MUST be stripped, never merely escaped-and-stored.

#### Scenario: Script injection is stripped, formatting survives

- GIVEN an author submits `content_html = "<script>alert(1)</script><strong>hola</strong>"`
- WHEN the announcement is saved
- THEN the stored `content_html` does NOT contain `<script>` in any form
- AND the stored `content_html` DOES contain `<strong>hola</strong>`
- AND the derived `content` equals `"hola"`

#### Scenario: Disallowed attributes are stripped, the tag survives

- GIVEN an author submits `content_html = "<p onclick=\"evil()\">texto</p>"`
- WHEN the announcement is saved
- THEN the stored `content_html` contains `<p>texto</p>` without the `onclick` attribute

---

### Requirement: content_html is not mass-assignable (R-RT-3)

`content_html` MUST NOT appear in the `Announcement` model's `$fillable` array. It MUST only ever be set through the sanitize-then-derive service method, never through `fill()`/`create()`/mass update with a raw request array.

#### Scenario: Mass assignment cannot bypass sanitization

- GIVEN a caller attempts `Announcement::create([..., 'content_html' => '<script>x</script>'])` directly (bypassing the service)
- WHEN the model processes the mass assignment
- THEN `content_html` is silently discarded by `$fillable` protection — it is never persisted verbatim from an unsanitized source

---

### Requirement: Rich-text authoring UI (R-RT-4)

Authoring an announcement's content MUST use a shared rich-text editor component supporting, at minimum: bold, italic, underline, bulleted lists, numbered lists, and links. The editor MUST be a controlled component (value/onChange) usable via a form library `Controller`, so it can be validated and submitted like any other form field.

#### Scenario: Authoring with formatting

- GIVEN a manager opens the announcement editor
- WHEN they apply bold to a phrase, add a bulleted list, and insert a link
- THEN the resulting `content_html` submitted to the server contains the corresponding `<strong>`/`<ul><li>`/`<a href="...">` markup

---

### Requirement: Rich-text rendering with plain-text fallback (R-RT-5)

Any surface that displays an announcement's content to a reader (the manager-facing reader dialog, the resident feed's detail view) MUST render `content_html` through a client-side sanitizer (allowlist mirroring the server's) when `content_html` is present and non-empty. When `content_html` is null or empty, the surface MUST fall back to rendering the plain `content` field as plain text (no HTML interpretation).

#### Scenario: Reader renders sanitized rich content

- GIVEN an announcement has `content_html = "<p>Corte de <strong>agua</strong></p>"`
- WHEN a user opens the reader view
- THEN the rendered output shows "Corte de **agua**" with bold applied, not raw HTML tags as text

#### Scenario: Reader falls back to plain text when content_html is absent

- GIVEN an announcement has `content_html = null` and `content = "Corte de agua"`
- WHEN a user opens the reader view
- THEN the rendered output shows the plain text "Corte de agua", with no attempt to parse it as HTML

---

### Requirement: Previews and notification excerpts stay plain-text (R-RT-6)

List-row previews, feed-card previews, and push/email notification excerpts MUST use the plain `content` field, truncated, and MUST NEVER render `content_html` or raw HTML in a preview context.

#### Scenario: Feed card preview is plain text even for rich content

- GIVEN an announcement has `content_html = "<p>Corte de <strong>agua</strong> programado para <em>mañana</em></p>"` and the derived `content = "Corte de agua programado para mañana"`
- WHEN the resident feed renders its card preview
- THEN the preview text shown is the plain, truncated `content` — no bold/italic markup, no HTML tags visible or interpreted

---

### Requirement: Read statistics — live audience resolution (R-STATS-1)

The read statistics for an announcement MUST be computed live, on each request, from the announcement's `target_type` and `target_selector` — using the same audience-resolution logic already used to preview an announcement's reach before publishing. No snapshot of the audience MUST be persisted at publish time or on any schedule; the denominator (who should read it) always reflects the CURRENT audience.

#### Scenario: Audience reflects current state, not a point-in-time snapshot

- GIVEN an announcement targets a tower with 2 units / 3 residents at publish time
- AND a 4th resident subsequently moves into one of those units
- WHEN the read-stats endpoint is called after the move
- THEN `target_people_count` is `4`, reflecting the current audience — not the audience at publish time

---

### Requirement: Read statistics — counts at unit and person level (R-STATS-2)

The read statistics endpoint MUST return, for the announcement's current audience: `target_units_count` (distinct units reached, or `null` when the target does not resolve to units — see the board-type requirement below), `target_people_count` (distinct people reached), `read_people_count`, `unread_people_count`, and `read_percentage` (an integer 0–100, `0` when `target_people_count` is `0` — never a division error).

#### Scenario: Counts are computed correctly

- GIVEN an announcement's audience resolves to 3 people, of which 1 has an `announcement_read` row
- WHEN the read-stats endpoint is called
- THEN `target_people_count = 3`, `read_people_count = 1`, `unread_people_count = 2`, `read_percentage = 33`

#### Scenario: Zero audience does not divide by zero

- GIVEN an announcement's audience resolves to 0 people (e.g. a `manual` target listing units with no current residents)
- WHEN the read-stats endpoint is called
- THEN `read_percentage = 0`, not an error

---

### Requirement: Read statistics — per-unit, per-resident breakdown with read date (R-STATS-3)

For unit-resolvable targets, the endpoint MUST return a `units` array where each entry carries `unit_id`, a human-readable `unit_label`, `read_count`, `total_count` (both reflecting the FULL unit, unaffected by any active status filter), and a `people` array of `{ user_id, name, read, read_at }` — `read_at` present (ISO datetime) when `read` is `true`, `null` otherwise.

#### Scenario: A unit's read breakdown lists every current resident

- GIVEN a targeted unit currently has 2 active residents, one of whom read the announcement
- WHEN the read-stats endpoint is called
- THEN that unit's entry has `total_count = 2`, `read_count = 1`, and its `people` array has exactly 2 entries — one with `read: true` and a `read_at` timestamp, one with `read: false` and `read_at: null`

---

### Requirement: Read statistics — board-type targets have no unit grouping (R-STATS-4)

When an announcement's `target_type` is `board`, the audience does not correspond to residential units. The endpoint MUST return `target_units_count: null` and an empty `units` array, and MUST instead return a flat top-level `people` array (same per-person shape as the unit breakdown) listing every board member reached.

#### Scenario: Board-targeted announcement has no unit grouping

- GIVEN an announcement targets `board` and reaches 5 board members, all of whom have read it
- WHEN the read-stats endpoint is called
- THEN `target_units_count` is `null`, `units` is an empty array, and the top-level `people` array has exactly 5 entries, all `read: true`

---

### Requirement: Read statistics endpoint is permission-gated and tenant-scoped (R-STATS-5)

The read statistics endpoint MUST require the same manager-level ability already used to gate audience-shaped data on this capability (the ability held by the announcement's authors/managers, NOT the base `read` ability every resident holds). It MUST run under tenant-scoping middleware, and every model it queries (`Announcement`, `AnnouncementRead`, the unit/resident pivot) MUST be tenant-scoped, so no cross-tenant data can appear in the response.

#### Scenario: A resident cannot enumerate who read an announcement

- GIVEN a user holds only the base read ability on announcements (a plain resident)
- WHEN they call the read-stats endpoint
- THEN the request is rejected (HTTP 403)

#### Scenario: Tenant isolation holds

- GIVEN tenant A and tenant B each have an announcement with reads recorded
- WHEN a tenant-A manager calls the read-stats endpoint for tenant A's announcement
- THEN no tenant-B `announcement_read` row or resident ever appears in the response

---

### Requirement: Read statistics — server-side pagination and filtering (R-STATS-6)

The endpoint MUST paginate its unit breakdown by unit (not by person) and MUST accept `status` (`all` | `read` | `unread`) and `search` (matches unit label or resident name) as query parameters, applied server-side BEFORE pagination. Applying a `status`/`search` filter MUST NOT change a unit's reported `read_count`/`total_count` — only which units/people are included in the current page's `people` listing.

#### Scenario: Pagination spans multiple pages for a large audience

- GIVEN an announcement's target resolves to more units than fit on one page
- WHEN the read-stats endpoint is called with `page=1`
- THEN the response's `meta.last_page` is greater than `1`, and calling with `page=2` returns the remaining units, none repeated

#### Scenario: Status filter narrows people shown without changing unit totals

- GIVEN a unit has `total_count = 2`, `read_count = 1`
- WHEN the read-stats endpoint is called with `status=unread`
- THEN that unit's `people` array contains only the unread resident, but `read_count` and `total_count` on the unit entry are unchanged (`1` and `2` respectively)

---

### Requirement: Read statistics panel is loaded lazily (R-STATS-7)

The frontend read-statistics panel MUST NOT fetch data until the user actively opens the "Lecturas" view for a specific announcement (e.g. by switching to that tab). It MUST NOT be fetched from the announcement list page, on mount of the editor page's default tab, or via any polling.

#### Scenario: No read-stats request fires until the tab is opened

- GIVEN a manager opens an announcement's editor page, landing on the "Contenido" tab
- WHEN no interaction with the "Lecturas" tab has occurred
- THEN no request to the read-stats endpoint has been made
- AND switching to the "Lecturas" tab triggers exactly one request

---

### Requirement: Announcement creation and editing happens on a dedicated page (R-PAGE-1)

Creating and editing an announcement MUST happen on a dedicated page with distinct "Contenido" and "Lecturas" tabs, not in a side-panel/Sheet overlay. The "Lecturas" tab MUST only be available when editing an existing (already-created) announcement — a not-yet-created draft has no audience to report on.

#### Scenario: Lecturas tab is unavailable while creating

- GIVEN a manager navigates to the announcement creation route (no existing announcement id)
- WHEN the editor page renders
- THEN the "Lecturas" tab is hidden or disabled — only "Contenido" is usable

#### Scenario: Lecturas tab becomes available once the announcement exists

- GIVEN a manager is editing an existing, previously-saved announcement
- WHEN the editor page renders
- THEN both "Contenido" and "Lecturas" tabs are available

---

### Requirement: Editor page routes are permission-gated (R-PAGE-2)

The create and edit routes MUST be protected by the same permission gate already used for the announcement module — no new permission entity is introduced by this change.

#### Scenario: An unauthorized user cannot reach the editor routes

- GIVEN a user lacks the announcement module's permission entity entirely
- WHEN they navigate to the create or edit route
- THEN they are redirected/blocked exactly as they would be on the existing `/announcement` route today
