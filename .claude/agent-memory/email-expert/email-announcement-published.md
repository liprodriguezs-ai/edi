---
name: email-announcement-published
description: The announcement-published transactional email — source, variables, and which channel sends it
metadata:
  type: project
---

Added 2026-07-04. Email that goes to residents when the cluster publishes an
Announcement (Announcements module).

- **Source:** `frontend/emails/announcement-published.tsx` (React Email), built from the
  shared `_components/` (EmailLayout, ButtonBase, Blade) + `_theme`. Compiles to
  `backend/Modules/Email/resources/views/announcement-published.blade.php`.
- **Sent by:** NOT a dedicated Mailable class — the Announcements publish flow routes
  through the template channel (`BladeEmailChannel`), which renders
  `view('email::announcement-published', $email_context + emailBaseUrl)`. The backend
  key is exactly `announcement-published` (filename must match or render breaks silently).
- **5 Blade vars (all guaranteed non-null by backend, NO `@if` in this flow):**
  `{{$title}}`, `{{$bodyText}}` (plain-text excerpt ≤600 chars), `{{$priorityLabel}}`
  ("Normal"/"Importante"/"Urgente"), `{{$audienceLabel}}` (human scope, e.g.
  "Por torre: Torre A" / "Todo el condominio"), `{{ $announcementUrl }}` (absolute,
  used directly as the CTA href — do NOT prefix with emailBaseUrl).
- The **"Dirigido a: {audienceLabel}"** block is the whole point — recipient must see scope.
- Preheader is GENERIC ("Nuevo aviso de tu condominio") on purpose — do not interpolate
  title/body into previewText (that bakes fallback data into the compiled Blade).
- Priority badge is a single neutral teal pill showing the label text (can't branch color
  by priority — no `@if`).
