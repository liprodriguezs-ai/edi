---
name: authoring-unused-props-lint
description: Email .tsx templates must only destructure props actually used as Blade fallbacks — unused defaults break pnpm lint (no-unused-vars)
metadata:
  type: feedback
---

Rule: in `frontend/emails/*.tsx`, destructure ONLY the props the JSX actually consumes
(typically as `fallback={x}` on `<Blade>` or `String(x)`). Detail-row tables use the
`detailRows[].valueFallback` constants, NOT the props — so props mirroring those rows
are dead code and trip `@typescript-eslint/no-unused-vars` on `pnpm lint`.

**Why:** 2026-07-19 lint cleanup removed 22 unused destructured props across 6 templates
(amenity-reservation-created, payment-accepted, payment-due-reminder,
payment-registered-direct, payment-registered, payment-rejected). The rendered HTML was
identical (vars were unused), so no recompile/`copy-emails` was needed.

**How to apply:** when authoring a new email, keep the Props interface + docblock full
(they document the Blade variables and type `PreviewProps`), but the component parameter
destructures only what the body references. If nothing is referenced, drop the parameter
entirely (`= () => (`) — the interface stays valid via the `PreviewProps as X` cast.
