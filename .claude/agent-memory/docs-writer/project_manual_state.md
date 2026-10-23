---
name: project-manual-state
description: State of the EDI end-user manual after the condo→EDI rebrand; which pages exist, condo leftovers, build quirks.
metadata:
  type: project
---

As of 2026-10-22 the manual has: `docs/index.md` (portada), `docs/agenda/citas.mdx`, `docs/expediente/nino.mdx`, `docs/expediente/prueba-edi.mdx` (sidebar group "Expediente del niño" → dir `expediente`).

- Registry `docsRegistry.ts` keys: `agenda`, `child`, `screening`. Other routes fall back to `/docs`.
- Roles that matter for docs: **Administrador** (everything) and **Recepción** (slug `assistant`, `roles.csv`): agenda + child registry, no `create.visit` (can't Iniciar atención), no screening, no nutrition. Allow-list in `RolePermissionTableSeeder::seedAssistantPermissions`.
- Still condo leftovers (humans to decide): style guide §1/§6 ("Residencial Las Palmas") and condo sidebar groups in `astro.config.mjs`. Avoid invented proper names in examples until a clinical example org exists.
- `pnpm build` in `landing/` may fail on `pnpm-workspace.yaml` parsing; fall back to `./node_modules/.bin/astro build`.
- `check-docs-links.mjs` does not count same-page `#ancla` links; verify against `dist/.../index.html` ids.

**Why:** avoids re-discovering scope, roles and build quirks.
**How to apply:** when adding a module page, add its sidebar `group()` and registry key. See [[agenda-page-sources]], [[clinical-record-page-sources]].
