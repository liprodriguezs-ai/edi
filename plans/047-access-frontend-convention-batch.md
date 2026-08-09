# Plan 047: Access frontend convention/quality batch

> **Executor instructions**: Each item is independent; apply all, verify after
> each, honor STOP conditions, update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat 0887d5a..HEAD -- src/modules/access`

## Status

- **Priority**: P3
- **Effort**: M
- **Depends on**: none (independent of 040–046)
- **Category**: tech-debt / convention
- **Planned at**: frontend submodule `0887d5a`, 2026-06-21

## Why this matters

A batch of convention and robustness fixes raised by the React reviewer against the
`react-frontend-architecture` skill. Individually minor; together they bring the Access module to the
codebase standard (error states, RHF+Zod everywhere, no unsafe casts, no cross-module
atom imports, typed resources, stable handlers).

## Current state (items)

1. **`CorrectionModal` uses raw `useState` instead of RHF+Zod** (`components/CorrectionModal.tsx:24`)
   and can be dismissed mid-submit (`onOpenChange={(open) => !open && close()}` at line 42
   has no `mutation.isPending` guard).
2. **`useMyAccessLog` swallows errors** (`hooks/useMyAccessLog.ts:7`) — exposes only
   `{ logs, lastPage, isLoading }`; `MyAccessLogPage` shows "Sin accesos registrados" on
   a failed fetch (misleading). Also no `staleTime` → refetch every mount.
3. **`AccessNowPage` loading state is `<p>Cargando…</p>`** (`AccessNowPage.tsx:80`) instead
   of a skeleton (anti-pattern: skeletons via `boneyard-js`).
4. **`StatusPill` imported cross-module** from `@/modules/treasurerPanel/components/StatusPill`
   in `AccessLogListPage.tsx:9`, `MyAccessLogPage.tsx:6`, `VisitorListPage.tsx:8` — it is now
   a shared atom used by 3+ modules.
5. **`VisitorResource` typed `Record<string, unknown>`** (`services/AccessResource.ts:5`)
   instead of `VisitorFormValues` — loses create/update payload type safety.
   `AccessLogResource` (line 18) similarly uses `Record<string, unknown>`.
6. **Unsafe `(item as { name?: string }).name` casts** on catalog items in
   `AccessNowPage.tsx:229,249` and `EntryRequestModal.tsx:112` (while `VisitorFormModal`
   reads `item.name` without a cast — inconsistent).
7. **Handlers not memoized**: `refresh` in `AccessLogListPage.tsx:72` passed to
   `<CorrectionModal>`; `handleApprove`/`handleReject` in `VisitorListPage.tsx:155-156`.
8. **`IVisitor` missing `access_logs` type** (`schema.ts`) — needed once the resident
   "Mis visitas" view reads per-visitor logs (plan 044).

## Commands you will need

| Purpose | Command | Expected |
|---|---|---|
| Typecheck | `cd frontend && pnpm build` | exit 0 |
| Lint | `cd frontend && pnpm lint` | no new errors (ideally fewer) |
| Tests | `cd frontend && pnpm test src/modules/access` | pass |

## Scope

**In scope**:
- `frontend/src/modules/access/components/CorrectionModal.tsx`
- `frontend/src/modules/access/hooks/useMyAccessLog.ts`
- `frontend/src/modules/access/MyAccessLogPage.tsx`
- `frontend/src/modules/access/AccessNowPage.tsx`
- `frontend/src/modules/access/AccessLogListPage.tsx`
- `frontend/src/modules/access/VisitorListPage.tsx`
- `frontend/src/modules/access/components/EntryRequestModal.tsx`
- `frontend/src/modules/access/services/AccessResource.ts`
- `frontend/src/modules/access/schema.ts`
- `frontend/src/shared/components/atoms/StatusPill.tsx` (create — move target)
- `frontend/src/modules/treasurerPanel/**` and `frontend/src/modules/payment/**` (ONLY the StatusPill import lines, if those modules import it)

**Out of scope**:
- Behavior changes to the gate/resident flows (covered by 040–045).
- The `useCatalogs` return type itself — if item 6 needs the hook's type widened, prefer
  a local typed interface over editing the shared hook unless trivial; if it requires a
  shared-hook change, that is a separate plan (note it).

## Steps

### Step 1: Move StatusPill to a shared atom

Move `treasurerPanel/components/StatusPill.tsx` to
`frontend/src/shared/components/atoms/StatusPill.tsx` (keep the same props/API). Update
ALL import sites (grep `from '@/modules/treasurerPanel/components/StatusPill'` across
`src/`) to the new path. Do not change its implementation.

**Verify**: `cd frontend && grep -rn "treasurerPanel/components/StatusPill" src/` → no
matches. `pnpm build` → exit 0.

### Step 2: Convert CorrectionModal to RHF+Zod and guard close-during-pending

Add `correctionFormSchema = z.object({ note: z.string().min(1,'La nota es requerida').max(1000) })`
to `schema.ts`. Replace the `useState('')` with `useForm` + `zodResolver`, `register('note')`
on the Textarea, and a `FieldError`. Change `onOpenChange` to
`(open) => !open && !mutation.isPending && close()`.

**Verify**: `pnpm build` → exit 0.

### Step 3: Surface errors + staleTime in useMyAccessLog

Return `isError` from the hook; add `staleTime: 30_000` to the `useQuery`. In
`MyAccessLogPage`, add an error branch (before the empty-state) rendering a clear
"No se pudo cargar tu bitácora" message (use the shared error/empty component if one
exists; otherwise a simple styled block).

**Verify**: `pnpm build` → exit 0.

### Step 4: Skeleton loading on AccessNowPage

Replace `<p>Cargando…</p>` with a `boneyard-js` `<Skeleton>` card-grid placeholder
(import `{ Skeleton } from 'boneyard-js/react'`), matching the card layout. If a quick
card skeleton is impractical, render 3 placeholder cards wrapped in `<Skeleton>`.

**Verify**: `pnpm build` → exit 0; `grep -n "Cargando" src/modules/access/AccessNowPage.tsx` → gone.

### Step 5: Type the resources and remove catalog-item casts

- `services/AccessResource.ts`: type `VisitorResource` as
  `createCrudApi<IVisitor, VisitorFormValues, VisitorFormValues, {...}>`. For
  `AccessLogResource`, the access_log is not form-created via Orion; keep its create/update
  generics as a minimal typed shape or `never` rather than `Record<string, unknown>` if
  that compiles cleanly.
- Remove `(item as { name?: string }).name` in `AccessNowPage` + `EntryRequestModal`;
  read `item.name` if the `useCatalogs` item type exposes it. If it does NOT, define a
  local `interface ICatalogItem { id: string; name: string }` and type the `.map(item: ICatalogItem)`.

**Verify**: `pnpm build` → exit 0; `grep -rn "as { name" src/modules/access` → no matches.

### Step 6: Memoize handlers

Wrap `refresh` (AccessLogListPage), `handleApprove`/`handleReject` (VisitorListPage) in
`useCallback` with correct deps (`[onSearch, paramsSearch]`, `[actionMutation]`).

**Verify**: `pnpm lint src/modules/access` → no new warnings; `pnpm build` → exit 0.

### Step 7: Add `access_logs` to IVisitor

Add `access_logs?: IAccessLog[] | null` to the `IVisitor` interface in `schema.ts`
(supports the resident "Mis visitas" per-visitor log display in plan 044).

**Verify**: `pnpm build` → exit 0.

## Test plan

- Extend access tests: `correctionFormSchema` rejects empty note and >1000 chars;
  `useMyAccessLog` exposes `isError`. Reuse the existing ScanQrModal test harness pattern.
- Verification: `cd frontend && pnpm test src/modules/access` → pass.

## Done criteria

- [ ] `cd frontend && pnpm build` exits 0
- [ ] `grep -rn "treasurerPanel/components/StatusPill" src/` → no matches
- [ ] `grep -rn "as { name" src/modules/access` → no matches
- [ ] CorrectionModal uses RHF+Zod and cannot close mid-submit
- [ ] useMyAccessLog returns `isError`; MyAccessLogPage renders an error state
- [ ] AccessNowPage loading uses boneyard skeleton (no "Cargando…" text)
- [ ] VisitorResource typed with `VisitorFormValues`
- [ ] `cd frontend && pnpm test src/modules/access` passes
- [ ] `plans/README.md` row updated

## STOP conditions

- Moving StatusPill breaks a treasurerPanel/payment import you can't locate → grep first;
  if it is re-exported anywhere, update that too. If circular, leave a re-export shim at
  the old path and note it.
- Typing `AccessLogResource` create/update generics produces cascading type errors in
  callers → keep a minimal typed shape and note the residual.

## Maintenance notes

- StatusPill is now a shared atom; future modules import from `shared/components/atoms`.
- The `useCatalogs` weak item type is the root cause of item 6's casts across the repo —
  a future shared-hook typing pass would remove the need for local `ICatalogItem`.
