# SDD Proposal — Anuncios: rich text, página dedicada y estadísticas de lectura

- **Change:** `announcements-richtext-read-stats`
- **Project:** `residia-monorepo` (Kivo7)
- **Phase:** fast-forward (proposal + delta spec + design + tasks produced together from an already-approved plan)
- **Artifact store:** hybrid
- **Date:** 2026-07-04
- **Source of truth:** formalizes the approved session plan "Anuncios 'pro': rich text (WYSIWYG) + página dedicada + estadísticas de lectura" (do not contradict it; the decisions below mirror it 1:1).

---

## 1. Intent

### Problem

Announcements today has three gaps:

1. **Authoring is plain text.** `AnnouncementComposeModal.tsx` uses a bare `<Textarea>` bound to `content` — no bold, no lists, no links.
2. **Presentation is raw.** `AnnouncementReadDialog.tsx` renders `content` with `whitespace-pre-wrap`; `AnnouncementFeedPage.tsx`'s `FeedCard` is a hand-rolled `<button>` block, not the shadcn `Card`/`Badge` primitives used elsewhere in the product.
3. **No read visibility beyond a count.** The list shows an aggregate `reads_count` column and `GET /announcement/{id}/metrics` returns only `reads_count` + `audience_count` totals. There is no way to see WHICH units/residents have read an announcement, or when.

Alta/edición also lives in a side `Sheet` (`AnnouncementComposeModal`), which is cramped for a rich editor plus a second "who read this" view.

### Why now

The project already ships this exact pattern in production, in Chat: a dual content field (`body` plain + `body_html` sanitized), server-side sanitization via `mews/purifier` (`ChatHtmlSanitizer`), TipTap authoring (`MessageComposer.tsx`), and DOMPurify rendering (`ChatMessageBubble.tsx`). Bringing the same pattern to Announcements replicates an already-audited seam of this repo — no new dependency, no new architectural risk.

### Success looks like

- Authoring a rich announcement (bold/italic/underline/lists/link) from a dedicated page.
- The stored HTML is sanitized server-side before persistence; the client's HTML is **never** trusted as-is.
- `searchableBy` and notification excerpts keep working unchanged, because `content` stays plain text — now derived server-side from the sanitized HTML instead of authored directly.
- A manager can open an announcement's "Lecturas" tab and see exactly which units/residents have read it, and when, at both the unit and person level.
- All existing endpoints (list, feed, board, publish/archive/read, `preview`, `metrics`) keep working unchanged — this is additive.

---

## 2. Scope

### In scope

1. **Dual content field (backend).** `content_html` (sanitized HTML, **not** mass-assignable) added to `announcement`. `content` (plain text) becomes **server-derived** from `content_html` via a shared `RichTextSanitizer` (extracted from the `ChatHtmlSanitizer` pattern — Chat itself is not touched).
2. **Shared rich-text frontend components.** `RichTextEditor` (TipTap author) and `RichTextContent` (DOMPurify render) in `frontend/src/shared/components/richtext/`, generalizing `MessageComposer`/`ChatMessageBubble`'s patterns so future modules can reuse them.
3. **Sheet → Page.** Alta/edición moves from `AnnouncementComposeModal` (Sheet) to a dedicated page `AnnouncementEditorPage` with tabs ("Contenido" / "Lecturas"), routes `/announcement/new` and `/announcement/:id/edit`.
4. **"Pro" redesign of read surfaces.** `AnnouncementReadDialog` renders sanitized rich HTML with a cleaner header (chips via `StatusPill`/`Badge`); `AnnouncementFeedPage`'s `FeedCard` migrates to shadcn `Card`/`Badge` primitives. Card/feed previews keep using plain `content` with `line-clamp-3` — **never** raw HTML in a preview.
5. **Read statistics (backend + frontend).** New endpoint `GET /announcement/{id}/read-stats`, computed **live** from the announcement's `target_type`/`target_selector` (reusing `AnnouncementService::audienceUserIds()` — the exact resolver behind `POST /announcement/preview`) crossed with `announcement_read`. Response gives counts at both the unit and person level plus a per-unit → per-resident breakdown with `read_at`. A new "Lecturas" tab in `AnnouncementEditorPage` (edit mode only) renders it, paginated by unit, loaded lazily only when the tab opens.

### Out of scope (explicit, per the approved plan)

- Rich HTML in the transactional email channel — email notifications keep the plain-text excerpt (`strip_tags` on `content`, unchanged).
- Restyling the admin `ResponsiveDataTable` list (`AnnouncementListPage`) — stays functional as-is. The "pro" redesign targets the reader (`AnnouncementReadDialog`) and the resident feed (`AnnouncementFeedPage`) only.
- Refactoring Chat — its sanitizer is the reference pattern, not something this change touches. Migrating Chat onto the extracted shared sanitizer is a separate, optional future effort.
- A read-stats snapshot table — the denominator (who *should* have read it) is computed live from the target on every request; no new table, no scheduled job.
- Any change to `POST /announcement/preview`, `GET /announcement/board`, `POST /announcement/{id}/publish|archive|read`, or `GET /announcement/{id}/metrics` — all untouched. `read-stats` is a new, separate endpoint with a materially richer contract than `metrics`.

---

## 3. Approach (intent/scope altitude — the HOW lives in design.md)

### Deliverable order (mirrors the plan's "Orden de ejecución")

1. Backend rich text — migration + `RichTextSanitizer` + model/requests/service wiring.
2. Backend read-stats — `AnnouncementReadStatsService` + `GET /announcement/{id}/read-stats`.
3. Frontend shared rich text — `RichTextEditor` + `RichTextContent`.
4. Frontend Sheet → Page — `AnnouncementForm` extraction, `AnnouncementEditorPage`, routes, list reconnection.
5. Frontend read stats — `readStats()` resource method + `AnnouncementReadStats` panel.
6. Frontend "pro" redesign — `AnnouncementReadDialog` + `FeedCard`.
7. Verify — backend tests, `pnpm build`/`pnpm lint`, manual QA, optional `/review-stack`.

### Rationale for the key decisions (detailed further in design.md)

- **TipTap, not a Notion-style block editor.** Already installed (`3.27.1`) and in production in Chat; adding a second WYSIWYG surface is configuration, not a dependency risk. Verified against upstream docs (Context7): TipTap v3's `StarterKit` bundles the `Link` mark — no new package needed for link support.
- **Dual field (`content` / `content_html`), server-derived.** Matches the exact Chat precedent (`body`/`body_html`); keeps `searchableBy(['title','content'])` and notification excerpting untouched.
- **Server-side sanitization is non-negotiable.** The client's HTML is never trusted; `content_html` stays out of `$fillable`, so it can only be set through the service after `RichTextSanitizer::sanitize()`.
- **Read-stats computed live, not snapshotted.** The target audience can change over time (units/residents move); the plan explicitly rejects a materialized table — KISS, and consistent with how `preview`/`board` already resolve audience on demand.
- **Page instead of Sheet.** The rich editor plus a second "Lecturas" tab need more room than a side panel comfortably gives. This is additive routing; the Sheet component is retired once the list reconnects to the new routes.

### Tenancy & security (hard requirements carried into design/spec)

- `content_html` sanitization happens server-side only; the field is never in `$fillable`.
- The read-stats endpoint is tenant-scoped (`initializeTenancy` + `BelongsToTenant` on every model it touches: `Announcement`, `AnnouncementRead`, `UnitUser`) and gated to managers (`update.announcement`) — the same stricter gate `preview` already uses for audience-shaped data, deliberately stricter than `metrics`'s `read.announcement` (residents must not enumerate who-read-what for an announcement).

---

## 4. Risks & open questions

- **Board-type audience has no unit grouping.** `target_type = 'board'` resolves to board-member user ids directly (via `AnnouncementService::audienceUserIds()`), which do not map to a single unit. The read-stats contract special-cases this: `units: []` plus a flat top-level `people[]` (see `design.md` §4.3).
- **No backfill for `content_html` on existing announcements.** The migration adds the column nullable; `AnnouncementReadDialog`/`RichTextContent` fall back to plain `content` (via a `fallbackText` prop) when `content_html` is null. Old announcements render as plain text until next edited — accepted, not a bug.
- **Two submodules (`backend`, `frontend`).** Per project convention, no worktrees for submodule edits — apply directly against the checked-out submodules.
- **Cross-stack single change.** Recommend `/review-stack` (integration + tenancy + react + laravel) and `@infra-cost-reviewer` before merge — the read-stats panel must stay lazy/paginated, never eager on the list or on mount.

---

## 5. Success criteria

- Creating/editing an announcement from `/announcement/new` / `/announcement/:id/edit` persists sanitized `content_html`; a `<script>` payload is stripped, formatting tags survive, `content` is the derived plain text.
- The resident reader and feed render sanitized HTML with a "pro" visual hierarchy; card/notification previews stay plain-text.
- The "Lecturas" tab shows `target_units_count`, `target_people_count`, `read_people_count`, `unread_people_count`, `read_percentage`, and an expandable per-unit → per-resident breakdown with `read_at`, paginated by unit and loaded only when the tab opens.
- `php artisan test` (Announcements module) green; `pnpm build` + `pnpm lint` green.
- No `any` in new TypeScript; UI 100% Spanish; zero eager read-stats calls on the list page.

---

## 6. Next phases

Spec, design and tasks are delivered together with this proposal (fast-forward, from an already-approved plan). Apply proceeds directly via `/sdd-apply`; `/review-stack` and `@infra-cost-reviewer` are recommended before merge (per `CLAUDE.md`).
