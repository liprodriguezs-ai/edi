# Plan 015: Add the "Register visit" form to the Visitantes page

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving on. If a
> "STOP condition" occurs, stop and report — do not improvise. When done, update
> this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd frontend && git diff --stat 1bcfbe3..HEAD -- src/modules/access`
> If `VisitorListPage.tsx` or the access module changed since this plan was
> written, compare the "Current state" excerpts to the live code first; mismatch = STOP.

## Status

- **Priority**: P1
- **Effort**: M
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: frontend commit `1bcfbe3`, 2026-06-11

## Why this matters

The Visitantes page (`/visitor`) **lists** visitor passes and lets you
approve/reject/cancel them, but there is **no way to REGISTER a new visit /
generate a QR**. The backend is fully ready — `Orion::resource('visitor', ...)`
exposes `POST /api/visitor` and `VisitorResource.create` already exists — but the
frontend has no form, no button, and `src/modules/access/components/` is empty.
A resident with `create.visitor` (the core §14 feature: residents generate
visitor QRs for their unit) literally cannot do it from the UI. This plan adds
the registration form.

## Current state

- `frontend/src/modules/access/VisitorListPage.tsx` — lists visitors; its
  `PageHeader` has NO action button. The page imports `VisitorResource` and a
  `useBaseList`. There is no create modal.
- `frontend/src/modules/access/components/` — **empty** (no form component).
- `frontend/src/modules/access/services/AccessResource.ts` — `VisitorResource`
  is built with `createCrudApi<IVisitor, ...>` so it already has `.create(data)`
  (POST to `/visitor`) in addition to the custom `approve/reject/cancelQr`.
- `frontend/src/modules/access/schema.ts` — `IVisitor` fields:
  `unit_id`, `name`, `visitor_motive_id`, `arrives_by_vehicle`, `plate`,
  `vehicle_model`, `qr_token`, `qr_expires_at`. There is already a partial form
  schema starting around line 53 (`unit_id: z.string().min(1, ...)`) — read it
  and extend it; do not duplicate.
- Catalogs available via `useCatalogs` (`src/shared/hooks/useCatalogs.ts`):
  `CatalogType.VISITOR_MOTIVE` and `CatalogType.QR_TYPE` (both exist).

**Backend create contract** (`backend/Modules/Access/app/Http/Requests/VisitorCreateRequest.php`):
- `unit_id` — required, uuid, must belong to the caller (rule `CallerBelongsToUnit`).
- `name` — required, max 255.
- `visitor_motive_id` — required, uuid (catalog `visitor_motive`).
- `qr_type_id` — required, uuid (catalog `qr_type`).
- `arrives_by_vehicle` — nullable boolean.
- `plate` — `required_if:arrives_by_vehicle,true`, max 20.
- `vehicle_model` — nullable, max 100.
- `id_document_type` — nullable, one of `ine|passport|driver_license|other`.
- `id_document_number` — nullable, max 100.
- `qr_expires_at` — nullable date, after now.
- `notes` — nullable, max 1000.

**Convention to follow**: model the modal on an existing form modal — read
`frontend/src/modules/collaborator/CollaboratorFormModal.tsx` (and
`CollaboratorForm.tsx`) for the project's Sheet/Dialog + React Hook Form + Zod +
`useCatalogs` select pattern, and the shared `CatalogCrudModule` form modal in
`src/shared/components/catalog/CatalogCrudModule.tsx` for the field/Controller idiom.
Toast: `import { toast } from '@/shared/hooks/useToast'`, use `toast.success('…')`
/ `toast.error('…')`. Money is not involved here.

## Commands you will need

| Purpose   | Command (from `frontend/`) | Expected            |
|-----------|----------------------------|---------------------|
| Typecheck | `pnpm exec tsc -b`         | exit 0, no errors   |
| Test      | `pnpm test`                | all pass            |
| Build     | `pnpm build`               | exit 0              |
| Lint      | `pnpm lint`                | no new errors       |

## Scope

**In scope**:
- `frontend/src/modules/access/schema.ts` — extend the visitor form schema (`visitorFormSchema` + `VisitorFormValues`) to cover the create fields above.
- `frontend/src/modules/access/components/VisitorFormModal.tsx` (create).
- `frontend/src/modules/access/VisitorListPage.tsx` — add a "Registrar visita" button (gated by `create.visitor`) that opens the modal; refresh the list on success.
- `frontend/src/modules/access/schema.test.ts` (create) — schema unit tests.

**Out of scope**:
- The backend (already complete — do not touch).
- The access guard panel (`AccessNowPage` / `/access`) and `AccessLogListPage`.
- The approve/reject/cancel actions (already work).

## Git workflow

- Branch: `advisor/015-visitor-registration-form`
- Conventional commit: `feat(access): add visitor registration form to Visitantes`
- Do NOT push/PR unless instructed. `frontend/` is a submodule — commit inside it.

## Steps

### Step 1: Extend the visitor form schema

In `schema.ts`, define (or extend the existing partial) `visitorFormSchema` (Zod)
covering: `unit_id` (required), `name` (required, max 255), `visitor_motive_id`
(required), `qr_type_id` (required), `arrives_by_vehicle` (boolean, default
false), `plate` (optional), `vehicle_model` (optional), `id_document_type`
(optional enum), `id_document_number` (optional), `notes` (optional). Add a
`superRefine` enforcing `plate` required when `arrives_by_vehicle` is true
(mirror the backend `required_if`). Export `VisitorFormValues = z.infer<...>`.

**Verify**: `pnpm exec tsc -b` → exit 0.

### Step 2: Create `VisitorFormModal.tsx`

A modal (Sheet or Dialog, matching `CollaboratorFormModal`) with React Hook Form
+ `zodResolver(visitorFormSchema)`. Fields:
- `unit_id`: a select. **Important** — a resident may only register for their OWN
  unit (`CallerBelongsToUnit`). Load the caller's units (reuse the same source
  `/my-unit` / `UnitResource.my()` used by `MyUnitPage`, or a unit combobox if a
  manager is registering). If the caller has exactly one unit, default+lock it.
- `name`: text input.
- `visitor_motive_id`: select from `useCatalogs({ [CatalogType.VISITOR_MOTIVE]: { _sort: 'order' } })`.
- `qr_type_id`: select from `CatalogType.QR_TYPE`. If there is a single sensible
  default QR type, preselect it.
- `arrives_by_vehicle`: switch; when on, show `plate` (required) and `vehicle_model`.
- `id_document_type` / `id_document_number`: optional.
- `notes`: optional textarea.

On submit call `VisitorResource.create(values)`; on success `toast.success('Visita
registrada')`, close, and refresh the list; on `ApiError` show
`formatApiErrorMessage(error)` (see `@/shared/api/apiClient`).

**Verify**: `pnpm exec tsc -b` → exit 0.

### Step 3: Wire the button into VisitorListPage

Add a primary button in the `PageHeader` row, gated by the `create.visitor`
permission (use `PermissionGuard permission="create.visitor"` or
`useMultiplePermissions`, matching how the page already reads `update.visitor`).
Clicking it opens `VisitorFormModal`. On modal success, call the page's existing
`refresh()`.

**Verify**:
- `pnpm exec tsc -b` → exit 0.
- `pnpm build` → exit 0.

### Step 4: Manual smoke (resident is the key user)

Run `pnpm dev`. Log in as a resident (`resident@encinos.test` / `123abc`), open
Visitantes, click "Registrar visita", fill name + motive + (their unit auto-set),
submit. Confirm a success toast and the new visitor appears in the list with a QR
expiry. Then try with `arrives_by_vehicle` on but no plate → the form blocks it.

## Test plan

Create `frontend/src/modules/access/schema.test.ts` (model on
`src/modules/unit/schema.test.ts` from plan 009):
- valid payload (no vehicle) parses;
- `arrives_by_vehicle: true` with empty `plate` FAILS;
- `arrives_by_vehicle: true` with a plate PASSES;
- missing `name` / `visitor_motive_id` / `qr_type_id` each fail.

**Verify**: `pnpm test` → all pass, including the new file.

## Done criteria

ALL must hold (from `frontend/`):

- [ ] `src/modules/access/components/VisitorFormModal.tsx` exists and is rendered by `VisitorListPage`.
- [ ] VisitorListPage has a `create.visitor`-gated "Registrar visita" button.
- [ ] `pnpm exec tsc -b` exits 0; `pnpm build` exits 0.
- [ ] `pnpm test` passes incl. the new schema tests (vehicle-requires-plate covered).
- [ ] Manual smoke: a resident can register a visit and it appears in the list.
- [ ] No out-of-scope files modified (`git status`).
- [ ] `plans/README.md` status row updated.

## STOP conditions

- "Current state" excerpts don't match (drift).
- The caller's unit list can't be loaded for the unit select (no `/my-unit` /
  unit source reachable) — report it; the form can't enforce `CallerBelongsToUnit`
  client-side without it.
- `POST /api/visitor` returns 422 for a payload that matches the documented
  contract above — capture the exact validation error and report; the backend
  contract may have drifted.
- `qr_type` catalog is empty for the tenant (no QR type to select) — report;
  registration can't proceed without a `qr_type_id`.

## Maintenance notes

- If new visitor fields are added to `VisitorCreateRequest`, extend both the Zod
  schema and the modal together.
- A reviewer should confirm a resident can only pick THEIR unit (the backend
  enforces it, but the UI should not offer other units), and that the
  vehicle-requires-plate rule matches the backend `required_if`.
- Approve/reject/cancel already exist; don't duplicate them in the modal.
