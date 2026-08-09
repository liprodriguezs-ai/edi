# Trial Countdown (API-Owned Days Left) Specification

> Replaces the earlier revision of this capability, which mandated a frontend `daysUntil` helper. Direction reversed by the user: the remaining-days logic belongs to the API, not the frontend ("toda esa lógica la debe de dar la api, no el front, no tengamos lógicas cruzadas").

## Purpose

One API-owned definition of "trial days left"; the frontend only renders it. Today two implementations coexist — frontend `daysUntil` (rounded elapsed milliseconds, Stripe `trial_ends_at` source) and backend `presentTrials.days_left` (calendar days, local `tenant_entitlement.expires_at` source) — differing in both algorithm and data source, so two different numbers for the same concept can render simultaneously.

## Requirements

### Requirement: Narrative exposes days left from the same source as trial_ends_at

The subscription narrative MUST expose the remaining-days count alongside `trial_ends_at`, derived from the SAME source datetime as the `trial_ends_at` it returns — never from a second store.

#### Scenario: Count and date cannot diverge

- GIVEN a trialing cluster whose narrative reports `trial_ends_at = T`
- WHEN the narrative is resolved
- THEN its days-left count is computed from `T` itself

### Requirement: One product-wide definition — calendar days

"Days left" MUST mean whole CALENDAR days in the app timezone (difference between today's start-of-day and the end date's start-of-day), clamped at 0. `presentTrials.days_left` MUST agree with the narrative's count for the same cluster at the same moment.

#### Scenario: presentTrials and narrative agree

- GIVEN a cluster on trial
- WHEN both `presentTrials.days_left` and the narrative's count are resolved at the same moment
- THEN they are equal

### Requirement: Frontend renders, never computes

The frontend MUST NOT compute the day count: `daysUntil` is removed from `frontend/src/shared/utils/dateHelper.ts` along with both call sites (`SelectPlanPage.tsx`, `UpcomingChargeBanner.tsx`). Those components MUST render the count the API sends.

#### Scenario: Zero frontend day-count computation

- GIVEN the implemented change
- WHEN the frontend tree is searched for `daysUntil` or an inline days-remaining formula in those components
- THEN zero occurrences remain AND both components display the API-provided number

### Requirement: Boundary behavior preserved

The first day of a 30-day trial MUST read 30, never 31; an elapsed trial MUST read 0, never negative. Backend tests MUST cover both boundaries.

#### Scenario: First day reads 30

- GIVEN a trial ending 30 days from now
- WHEN the count is resolved on day one
- THEN it is 30, not 31

#### Scenario: Elapsed trial clamps to zero

- GIVEN a trial whose end date is in the past
- WHEN the count is resolved
- THEN it is 0, never negative
