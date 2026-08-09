---
name: pipeline-copy-emails-scoping
description: copy-emails regenerates ALL blades incl. ones with no committed blade — how to scope the diff
metadata:
  type: feedback
---

`pnpm copy-emails` regenerates a blade for EVERY `.tsx` in `frontend/emails/`, then
copies them to `backend/Modules/Email/resources/views/`.

**Why:** several `.tsx` exist whose `.blade.php` is deliberately NOT committed
(as of 2026-07-04: `loyalty-recovery`, `loyalty-reward-earned`, `loyalty-welcome`,
`payment-receipt`). copy-emails will freshly generate those blades → they show as
untracked `??`. It also drops a stray hashed static asset under
`views/static/` (e.g. `40_122677.svg`) that no blade references.

> **Corrección 2026-08-08:** los 4 blades de arriba (`loyalty-*`, `payment-receipt`)
> YA están commiteados — hoy no aparecen como `??`, aparecen como `M` si driftan.
> El artefacto suelto de hoy fue `views/static/logo.png` (el `40_122677.svg` ya está
> trackeado; el home real de los assets es `backend/public/email/static/`, donde
> `logo.png` sí está trackeado y no cambió). Regla estable: cualquier `??` bajo
> `views/static/` es basura del export → `rm`.

**Ruido de recompilación observado (2026-08-08, react-email 5.2.x):** dos formas,
ambas inocuas — (a) la línea de padding invisible del preview-text, (b) marcadores
separadores de texto `<!-- -->` que aparecen/desaparecen. Si el ÚNICO cambio de un
blade es una de esas dos, revertilo. Si están dentro de un blade que sí tenías que
regenerar, se van con el cambio bueno y no se pueden aislar: es esperado, no un bug.

**How to apply:** after copy-emails, to keep the diff to ONLY your target email:

1. `git -C backend checkout -- <each drifted tracked blade>` (preview-text padding
   drifts between react-email versions → noise).
2. `rm` the newly-generated untracked blades for emails you did NOT touch
   (git checkout won't remove untracked files).
3. `rm` the stray unreferenced `views/static/*.svg` export artifact.
4. `rm -rf frontend/out`.
   Verify: `git -C backend status --short -- Modules/Email/resources/views` should show
   only your new blade.

Verify render for real (Mail::fake does NOT render Blade): host php
`php artisan tinker --execute` rendering `view('email::<name>', [...vars, emailBaseUrl])->render()`
and assert your strings/vars appear.
