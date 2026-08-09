# Plan 079: Give the reservation screens real error states, and stop the wizard submitting an empty `unit_id`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> ```
> git -C frontend diff --stat 7d4e05e..HEAD -- src/modules/reservation
> ```
> On any change, compare against the "Current state" excerpts; on a mismatch,
> treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: monorepo `48f55be`, frontend submodule `7d4e05e`, 2026-08-04

## Why this matters

Three screens in the new reservation module were built on raw `useQuery` rather
than the shared `useBaseList` hook, and none of them destructures `isError`. The
axios interceptor only raises a toast for one specific business error
(`SUBSCRIPTION_INACTIVE`), so a 5xx or a dropped connection produces **no visible
signal at all**.

The failure modes are worse than a missing error message:

- Both calendars render **"Sin reservaciones en el rango visible."** — a
  confident, authoritative empty state — when in fact the request failed. An
  admin reads "this amenity had no bookings this month" and may act on it.
- `MyReservationsPage` computes its loading flag from `isSuccess`, which never
  becomes true on error, so the resident sees a **skeleton that never resolves**.

Separately, `ReserveAmenityWizard` derives the unit from a query it never checks:
when the units request fails or has not landed, a single-unit resident reaches
"Confirmar reservación" and submits `unit_id: ''`, getting an opaque 422 after
filling in the whole wizard.

## Current state

### Calendar 1 — reservations

```tsx
// frontend/src/modules/reservation/AmenityReservationCalendarPage.tsx:79-95
  const { data, isLoading } = useQuery({
    queryKey: ['amenity-reservation', 'calendar', visibleRange.start, visibleRange.end, amenityIds],
    queryFn: () =>
      AmenityReservationResource.search({
        ...
        limit: 300,
      }),
    staleTime: 30 * 1000,
  })
```

```tsx
// frontend/src/modules/reservation/AmenityReservationCalendarPage.tsx:169-173
        {calendarApp && !isLoading && reservations.length === 0 && (
          <p className='py-3 text-center text-sm text-muted-foreground'>
            Sin reservaciones en el rango visible.
          </p>
        )}
```

### Calendar 2 — occupancy

```tsx
// frontend/src/modules/reservation/AmenityOccupancyCalendarPage.tsx:56-60
  const { data, isLoading } = useQuery({
    queryKey: ['amenity-reservation', 'occupancy', visibleRange.start, visibleRange.end],
    queryFn: () => AmenityReservationResource.occupancy(visibleRange.start, visibleRange.end),
    staleTime: 30 * 1000,
  })
```

```tsx
// frontend/src/modules/reservation/AmenityOccupancyCalendarPage.tsx:105-109
        {calendarApp && !isLoading && entries.length === 0 && (
          <p className='py-3 text-center text-sm text-muted-foreground'>
            Sin ocupación registrada en el rango visible.
          </p>
        )}
```

### The infinite skeleton

```tsx
// frontend/src/modules/reservation/MyReservationsPage.tsx:107-110
  const { data: ownUnitsData, isSuccess: ownUnitsLoaded } = useQuery({
    queryKey: ['unit', 'my'],
    queryFn: () => UnitResource.my(),
  })
```

```tsx
// frontend/src/modules/reservation/MyReservationsPage.tsx:158
  const isPageLoading = !ownUnitsLoaded || (unitIds.length > 0 && isLoading)
```

If `UnitResource.my()` rejects, `isSuccess` stays false forever, so
`isPageLoading` stays true forever.

### The empty `unit_id`

```tsx
// frontend/src/modules/reservation/components/ReserveAmenityWizard.tsx:200-217
  const { data: ownUnits = [] } = useQuery({
    queryKey: ['unit', 'my'],
    queryFn: () => UnitResource.my(),
  })

  const steps = useMemo(
    () => buildReserveWizardSteps(amenity, ownUnits.length),
    [amenity, ownUnits.length]
  )
  ...
  const [manualUnitId, setManualUnitId] = useState<string | null>(null)
  const defaultUnitId = ownUnits.length === 1 ? ownUnits[0].id : ''
  const unitId = manualUnitId ?? defaultUnitId
```

```ts
// frontend/src/modules/reservation/reserveWizard.ts:37-47
export function buildReserveWizardSteps(
  amenity: Pick<IAmenity, 'rules_text'>,
  ownUnitsCount: number
): ReserveWizardStep[] {
  const steps: ReserveWizardStep[] = []
  if (ownUnitsCount > 1) steps.push('unit')
  steps.push('datetime')
  if (amenity.rules_text) steps.push('rules')
  steps.push('review')
  return steps
}
```

With `ownUnits === []` (the default while loading, and forever on error), the
`unit` step is skipped, `defaultUnitId` is `''`, and:

```ts
// frontend/src/modules/reservation/reserveWizard.ts:171-180
  const payload: ReservationCreatePayload = {
    amenity_id: params.amenityId,
    unit_id: params.unitId,
    reservation_date: params.reservationDate,
  }
```

forwards the empty string unchanged.

### The pattern to copy — already in this codebase

```tsx
// frontend/src/modules/amenity/components/AmenityAvailabilityCalendar.tsx:103-113
        {isLoading && !data ? (
          <p className='px-3 py-8 text-center text-[13px] text-[var(--ink-3)]'>
            Cargando disponibilidad…
          </p>
        ) : isError ? (
          <p className='px-3 py-8 text-center text-[13px] text-[var(--ink-3)]'>
            No se pudo cargar la disponibilidad. Intenta de nuevo.
          </p>
        ) : (
```

### Repo conventions that apply

- All UI text in Spanish (Mexican: "tú", not voseo).
- Toasts come from `sileo` via `@/shared/hooks/useToast` — `toast.error('msg')`.
  **Never** shadcn's `useToast()` / `toast({title, variant})`.
- Skeletons use `boneyard-js` `<Skeleton>`, never Tailwind `animate-pulse`.
- No `any`; strict TypeScript.
- Tests: vitest, colocated in `__tests__/`. `src/modules/reservation` already has
  test files — read one before writing.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Typecheck | `cd frontend && pnpm exec tsc -p tsconfig.app.json --noEmit` | exit 0 |
| Lint | `cd frontend && pnpm lint` | 0 errors, ≤ 44 warnings |
| Tests | `cd frontend && pnpm test` | all pass |
| Focused | `cd frontend && pnpm exec vitest run src/modules/reservation` | all pass |

Do **not** run `pnpm build` and do not install anything.

## Scope

**In scope**:
- `frontend/src/modules/reservation/AmenityReservationCalendarPage.tsx`
- `frontend/src/modules/reservation/AmenityOccupancyCalendarPage.tsx`
- `frontend/src/modules/reservation/MyReservationsPage.tsx`
- `frontend/src/modules/reservation/components/ReserveAmenityWizard.tsx`
- `frontend/src/modules/reservation/__tests__/reserveWizard.test.ts` (extend if it
  exists; create if not)

**Out of scope** (do NOT touch, even though they look related):
- `frontend/src/shared/api/apiClient.ts` — adding a global 5xx toast is a
  plausible idea but it would change behavior on every screen in the app. That is
  a separate, wider decision.
- `useBaseList` — migrating these screens onto it is a bigger refactor; this plan
  only adds the missing error branch.
- `reserveWizard.ts`'s `buildReserveWizardSteps` — the step logic is correct for
  its inputs. The fix is to not call it with unloaded data.
- The `limit: 300` silent truncation on the calendar — a real but separate item.
- Any change to `AmenityReservationResource`.

## Git workflow

- Branch: `advisor/079-reservation-error-states`
- Suggested commits:
  - `fix(reservation): show an error state instead of a false empty state`
  - `fix(reservation): block wizard submission until the resident's units load`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add the error branch to both calendars

In `AmenityReservationCalendarPage.tsx`, destructure `isError` from `useQuery`,
and change the conditional block at `:169-173` so the three states are mutually
exclusive and error wins over empty:

- `isError` → a retry message, following the wording style of
  `AmenityAvailabilityCalendar`: `No se pudo cargar las reservaciones. Intenta de nuevo.`
- `!isLoading && !isError && reservations.length === 0` → the existing empty copy.

Do the same in `AmenityOccupancyCalendarPage.tsx` at `:105-109`, with
`No se pudo cargar la ocupación. Intenta de nuevo.`

Keep the existing `calendarApp &&` guard. Do not add a toast — these are
persistent page states, and a toast would disappear before the user reads it.

**Verify**:
```
cd frontend && grep -n "isError" src/modules/reservation/AmenityReservationCalendarPage.tsx src/modules/reservation/AmenityOccupancyCalendarPage.tsx
```
→ each file shows `isError` both in the destructure and in the render branch.

### Step 2: Fix the infinite skeleton on `MyReservationsPage`

Destructure `isError: ownUnitsError` alongside `isSuccess: ownUnitsLoaded` from
the `['unit','my']` query, and:

1. Change `isPageLoading` so an errored units query is **not** treated as still
   loading:
   ```tsx
   const isPageLoading = (!ownUnitsLoaded && !ownUnitsError) || (unitIds.length > 0 && isLoading)
   ```
2. Render an explicit error state when `ownUnitsError` is true, instead of the
   table — with copy like `No se pudieron cargar tus unidades. Intenta de nuevo.`
3. Also destructure `isError` from the reservations query and show the same kind
   of message in place of the table when it fails.

**Verify**:
```
cd frontend && grep -n "ownUnitsError\|isError" src/modules/reservation/MyReservationsPage.tsx
```
→ both queries' error flags are read and used.

### Step 3: Block the wizard until units have loaded

In `ReserveAmenityWizard.tsx`, destructure the query's status:

```tsx
  const { data: ownUnits = [], isLoading: unitsLoading, isError: unitsError } = useQuery({
    queryKey: ['unit', 'my'],
    queryFn: () => UnitResource.my(),
  })
```

Then:

1. **Do not render the wizard body until the units query has settled.** While
   `unitsLoading`, render a loading state; on `unitsError`, render an error
   message with retry copy. This is what stops `buildReserveWizardSteps` being
   called with `0` when the real answer is "not known yet" — which is the root of
   the empty `unit_id`.
2. **Belt and braces**: add `Boolean(unitId)` to the `disabled` condition of the
   final "Confirmar reservación" button, so even an unforeseen path cannot submit
   an empty unit.

Do not change `buildReserveWizardSteps` or `buildReservationCreatePayload`.

**Verify**:
```
cd frontend && grep -n "unitsLoading\|unitsError" src/modules/reservation/components/ReserveAmenityWizard.tsx
```
→ both present and used in the render path.

### Step 4: Add a unit test for the payload guard

The wizard's submit path is easiest to pin at the pure-function level, which is
also this repo's strongest existing test pattern (`money.test.ts`,
`buildTargetSelector.test.ts`).

Extend (or create) `frontend/src/modules/reservation/__tests__/reserveWizard.test.ts`
— read any existing test in `src/modules/reservation/__tests__/` first for the
import and assertion style.

Cases:

1. `buildReservationCreatePayload` with a non-empty `unitId` produces
   `unit_id` set correctly (baseline).
2. `buildReserveWizardSteps(amenity, 0)` does **not** include the `'unit'` step —
   documenting precisely why the component must not call it with an unsettled
   count. Add a comment in the test naming that reason, so the coupling is
   explicit for the next reader.
3. `buildReserveWizardSteps(amenity, 1)` also omits `'unit'` (single unit is
   auto-selected) and `(amenity, 2)` includes it.

**Verify**: `cd frontend && pnpm exec vitest run src/modules/reservation`
→ all pass.

### Step 5: Full frontend check

```
cd frontend && pnpm exec tsc -p tsconfig.app.json --noEmit
cd frontend && pnpm lint
cd frontend && pnpm test
```

**Verify**: typecheck clean; lint 0 errors and no more than 44 warnings (report
the number, and flag it if it went up); all tests pass.

## Test plan

- **New tests**: 3 cases in `reserveWizard.test.ts` covering the payload and the
  step-derivation contract that the component depends on.
- **Structural pattern**: existing tests under
  `frontend/src/modules/reservation/__tests__/`.
- **Honest limitation to state in your report**: the error-state rendering in
  Steps 1–3 is **not** covered by tests — these are page components with calendar
  libraries and query providers, and a render test for them would be
  disproportionate. The verification for those steps is the greps plus a manual
  read. Say so; do not imply coverage you do not have.
- **Optional, if cheap**: if `src/modules/reservation/__tests__/` already contains
  a component render test with a QueryClient wrapper, add one case asserting the
  error copy appears when the query is made to reject. Only do this if the harness
  already exists — do not build one.
- **Verification**: `pnpm test` — all pass.

## Done criteria

ALL must hold:

- [ ] `grep -c "isError" src/modules/reservation/AmenityReservationCalendarPage.tsx` returns ≥ `2`
- [ ] `grep -c "isError" src/modules/reservation/AmenityOccupancyCalendarPage.tsx` returns ≥ `2`
- [ ] `grep -c "isError" src/modules/reservation/MyReservationsPage.tsx` returns ≥ `2`
- [ ] `MyReservationsPage`'s `isPageLoading` no longer stays true when the units query errors (verified by reading)
- [ ] `ReserveAmenityWizard` does not render its steps until the units query settles, and the confirm button is disabled without a `unitId`
- [ ] All new user-facing copy is in Spanish
- [ ] No `animate-pulse` was introduced; no shadcn `useToast` was used
- [ ] `cd frontend && pnpm exec tsc -p tsconfig.app.json --noEmit` exits 0
- [ ] `cd frontend && pnpm lint` → 0 errors, ≤ 44 warnings
- [ ] `cd frontend && pnpm test` passes
- [ ] `git -C frontend status --porcelain` lists only the files in Scope
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **Adding a loading gate to `ReserveAmenityWizard` breaks its opening
  animation or its dialog mount** (the wizard may be inside a Sheet that expects
  children immediately). Report what you observe; the fallback is to keep the
  body mounted and only gate the *submit*, which still fixes the empty
  `unit_id`.
- **`pnpm lint` warnings go above 44.** That is the measured baseline; a rise
  means you introduced something.
- **A test starts failing that asserts the empty-state copy appears on error.**
  That would mean the false empty state was, implausibly, intentional. Report it
  rather than changing the test.
- **You find that `UnitResource.my()` already has a retry/suspense wrapper**
  higher in the tree that makes the error unreachable. Then the fix is
  unnecessary for that screen — report it instead of adding dead branches.
- **You are tempted to add a global 5xx toast to `apiClient`.** Out of scope; it
  changes every screen. Note the idea in your report.

## Maintenance notes

- **The underlying pattern issue**: screens built on raw `useQuery` do not inherit
  the error handling that `useBaseList` provides (it at least raises a toast).
  Every future screen that skips `useBaseList` will have this gap. The durable fix
  is either migrating them onto the shared hook or adding a global transport-error
  toast — both larger decisions, both deliberately out of scope here.
- **What a reviewer should scrutinize**: that error takes precedence over empty in
  the render conditionals. If the branches are ordered the other way, a failed
  request still shows the reassuring empty state and the bug is unfixed.
- **Still open on these screens** (not this plan): the calendar caps at
  `limit: 300` with no indication when the cap bites, so a busy month renders
  silently incomplete. Worth its own small change — compare `data.total` against
  the returned length and show a "mostrando N de M" banner.
