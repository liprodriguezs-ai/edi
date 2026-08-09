---
name: announcement-edit-capability
description: Review findings for edit mode added to AnnouncementComposeModal and AnnouncementListPage (June 2026)
metadata:
  type: project
---

## Summary

Edit capability added to Announcements module by reusing `AnnouncementComposeModal`.
Two files changed: `AnnouncementComposeModal.tsx` + `AnnouncementListPage.tsx`.

## What was correct

- Permission gate: both desktop (`{canUpdate && <TableCell>}`) and mobile (`renderActions={canUpdate ? fn : undefined}`) paths properly gated.
- Status gate: `isDraft || isPublished` derived from `ANNOUNCEMENT_STATUS` constants in both paths; archived rows get no edit button.
- Form hydration: all 9 `AnnouncementFormValues` fields covered with correct `?? ''` / `?? false` fallbacks; `null ?? ''` fires for nullable strings.
- Stale state safe: "Nuevo aviso" button atomically sets `null + CREATE + open` in one React batch; `onClose` resets `selectedAnnouncement`.
- `StatusPill` import fixed to `shared/components/atoms/StatusPill` in this same batch (plan 047 fix carried over).

## Open issues

### expires_at date format risk (WARNING)
`AnnouncementComposeModal.tsx:125` — `expires_at` hydrated directly from entity.
If backend returns ISO datetime (`2026-07-15T00:00:00Z`), `<input type="date">` shows blank and update sends `null`, silently clearing the date.
Fix: `.slice(0, 10)` on hydration. **Needs backend column type verification** (`date` vs `timestamp`).

### Attachments invisible in edit mode (product gap)
`AnnouncementComposeModal.tsx:315` — `{!isEdit && <AttachmentsField>}` hides attachments section entirely.
Existing `media` on the entity is not displayed. User cannot see, add, or remove attachments when editing.
The list page already fetches `{ relation: 'media' }` so the data is available. At minimum render existing attachments read-only.

### Double handleSubmit (recurring project-wide anti-pattern)
`AnnouncementComposeModal.tsx:255,332` — `<form onSubmit={handleSubmit(...)}>` AND `<Button onClick={handleSubmit(...)}>`.
No `type="submit"` inside the form; `form.onSubmit` is dead code. The button calls `handleSubmit` directly.
Should use `submitRef` pattern (react-frontend-architecture skill §2.9). Same pattern found in Access module modals — not fixed here.

### Inline multi-statement edit handler (3 copies)
`AnnouncementListPage.tsx:112,192,257` — identical `() => { setSelectedAnnouncement(x); setModalMode(EDIT); setComposeOpen(true) }` appears 3 times.
Should be extracted into a `useCallback` `handleEdit`.
