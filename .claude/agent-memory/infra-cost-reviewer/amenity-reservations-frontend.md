---
name: amenity-reservations-frontend
description: Cost review of the AmenityReservations frontend (S12-S16, feat/amenity-reservations, uncommitted) — confirmed gold-standard lazy-catalog/bounded-range loading; one avoidable round-trip found (amenity.bank_account on admin payment registration)
metadata:
  type: project
---

Reviewed 2026-07-07 (uncommitted work on `frontend` submodule, `feat/amenity-reservations`
branch — same commit as `main`, changes are untracked `src/modules/amenity/**` +
`src/modules/reservation/**` + `useCatalogs.ts` CatalogType additions + `payment/schema.ts`
EARMARK_TYPE.AMENITY_RESERVATION).

**Confirmed CLEAN (do not re-flag) — this is the best-executed catalog/availability loading
seen in the project so far, cite as reference alongside `DocumentFormModal`:**

- `AmenityAvailabilityCalendar.tsx` (shared by browse-detail S14 + reserve wizard S15):
  `GET /amenity/{id}/availability` bounded to the VISIBLE month (with today-clamping so it
  never requests before "today"), `queryKey` includes `[amenity.id, rangeStart, rangeEnd]`,
  gated `enabled: Boolean(id) && !out_of_service && rangeStart<=rangeEnd`. Refetches only on
  month navigation (`onMonthChange`), never per-day-loop. Textbook bounded-range fetch.
- `AmenityReservationCalendarPage.tsx` (schedule-x admin calendar, S13b): reservations fetched
  for the visible range ONLY (7-day padding + `onRangeUpdate` correction), `queryKey` keyed by
  `[range.start, range.end, amenityIds]`, `staleTime: 30s`, hard `limit: 300` safety cap. Color
  mapping (`amenityCalendarId`) is a deterministic hash bucket, zero extra amenity lookups —
  title reads `reservation.amenity?.name` already present via the `includes: [{relation:
  'amenity'},{relation:'unit'}]` on the SAME query. No N+1.
- Filter catalogs: `reservation_status` via `useLazyCatalogs` + `MultiSelectFilter
  onFirstOpen`; Amenity filter via a NEW shared hook `useAmenityFilterOptions` (lazy,
  `staleTime: 5min`, `limit:200`, shared verbatim by `ReservationFiltersPanel` (list) AND
  `AmenityReservationCalendarPage` (calendar) — same `queryKey` so React Query dedupes if both
  mount in the same window). `Amenity` isn't in the backend `/selects` mapper so this
  standalone-lazy-query pattern is the correct fallback shape — worth citing as the template
  for any future catalog that isn't select-mapped.
- Form catalogs: `AmenityForm.tsx` uses eager `useCatalogs` (RESERVATION_MODE, PAYMENT_TIMING,
  BANK_ACCOUNT) but this is NOT a violation — `AmenityFormPage` is a dedicated PAGE (not a
  modal rendered unconditionally by the list), so navigating to it already IS "opening the
  form" (same reasoning as Announcement's full-page editor). The code even has an inline
  comment citing skill §2.12 for this exact reasoning — good, self-documenting practice by
  whoever built this.
- Wizard (`ReserveAmenityWizard.tsx`) and both payment sheets
  (`SubmitReservationPaymentSheet.tsx`, `RegisterReservationPaymentSheet.tsx`) all use the gold
  `if (!isOpen) return null` + extracted `*Body` child pattern — hooks/catalogs/queries live
  only in the child that mounts on open. `useLazyCatalogs` + immediate `enable()` inside the
  body's `useEffect` is the correct shape (mounts only when the sheet opens, so "eager inside
  the lazy-mounted child" = fine).
- Lists (`AmenityListPage`, `AmenityCatalogPage`, `AmenityReservationListPage`,
  `MyReservationsPage`): all paginated via `useBaseList`/manual page state, minimal `includes`
  (only relations actually rendered in the row), no polling, no `refetchInterval`.
- Global `queryClient` defaults are good for this kind of range-bounded UI:
  `refetchOnWindowFocus: false`, `staleTime: 30s` — reopening a dropdown/calendar range within
  30s does not refetch.

**WARNING found — avoidable extra round-trip on admin "Registrar pago" (bounded, not
CRITICAL since it's click-triggered not mount-triggered, but a real duplicate query):**

`RegisterReservationPaymentSheet.tsx` (`RegisterReservationPaymentSheetBody`, ~line 98) does
its own `useQuery(['amenity','bank-account', reservation.amenity_id], () =>
AmenityResource.show(id, {include:'bank_account'}))` on open. But the PARENT page
(`AmenityReservationDetailPage.tsx`, `DETAIL_INCLUDE`, ~line 31-39) already fetched the SAME
amenity via `{relation:'amenity'}` moments earlier when loading the reservation detail — it's
just missing the ONE nested relation (`bank_account`, for the display name). Since
`DETAIL_INCLUDE` is a single-row query already happening on every detail page visit, appending
`'amenity.bank_account'` there is free (one more join on one row) and would let
`AmenityReservationDetailPage` pass `reservation.amenity` straight down as the `amenity` prop
(same shape `SubmitReservationPaymentSheet` already expects: `Pick<IAmenity,
'bank_account_id'|'bank_account'>`) instead of `RegisterReservationPaymentSheetBody` firing a
brand-new `/amenity/{id}` request on every "Registrar pago" click. Fix: add
`'amenity.bank_account'` to `DETAIL_INCLUDE`, drop the internal `useQuery` in
`RegisterReservationPaymentSheetBody`, accept `amenity` as a prop instead.

Same shape also exists in `MyReservationsPage.tsx` (~line 101-105, resident "Pagar" flow) but
there it's a defensible tradeoff, NOT flagged: the list query only includes bare `amenity` (no
nested bank_account) across a PAGINATED page of up to 20 rows, most of which the resident will
never click "Pagar" on in a given session — eager-nesting `bank_account` into every list row
just in case would cost more DB-side than the current one-request-per-click (cached 30s by
queryKey). Keep this distinction in mind: same pattern, different verdict depending on whether
the "already-fetched" data is a single-row detail (fix it) or a paginated list (leave it lazy).

**Not worth flagging (checked, intentional convention, not a cost issue):**
- `useReservationDetailData`'s payments query (`AmenityReservationDetailPage.tsx`) gates
  `enabled: Boolean(id) && isSuccess` on the reservation query's `isSuccess` — this is
  `useEntityDetail`'s documented convention ("expose isSuccess so dependent queries only fire
  after the entity actually loaded"), adds latency (waterfall) but not extra requests. Same
  `queryKey` `['unit','my']` used independently in both `MyReservationsPage` and
  `ReserveAmenityWizard` — naturally deduped by React Query's cache, no extraction needed.
- `AmenityGallery.tsx`'s sequential `for` loop uploading files one at a time is a UX-latency
  choice, not a cost multiplication (bounded by `MAX_IMAGES=20`, user-initiated one-off action).

**Tenancy note (out of scope for this reviewer, already self-documented in the code, hand off
to `@tenancy-reviewer` if this branch is picked up for review):** `MyReservationsPage.tsx` has
an inline comment flagging that `AmenityReservationController` does NOT use
`ScopesToResidentUnits` — the `unit_id in [...]` filter is UI-only, not a backend scope. Not an
infra-cost issue, but worth relaying since it's adjacent to code I read.
