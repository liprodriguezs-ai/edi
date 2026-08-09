# Cashier Timestamp Timezone Specification

## Purpose

Stripe Unix timestamps persisted through Cashier must land as app-timezone wall clock, consistent with every other datetime in the schema. Carbon 3 changed `Carbon::createFromTimestamp` to return UTC (Carbon 2 returned the app timezone); with `timestamp without time zone` columns and `APP_TIMEZONE=America/Mexico_City`, the three affected columns are stored 6 hours in the future. Measured: `subscriptions.created_at` (Eloquent) agrees with local time while `subscriptions.trial_ends_at` (via `createFromTimestamp`) is +6h on the same row.

## Requirements

### Requirement: Stripe-derived timestamps stored in app timezone

The three columns Cashier persists from `createFromTimestamp` results — `subscriptions.trial_ends_at`, `subscriptions.ends_at`, and `user.trial_ends_at` — MUST hold the app-timezone wall clock, consistent with `subscriptions.created_at` and every other datetime column.

#### Scenario: 30-day trial stores the local wall clock

- GIVEN a subscription created now with a 30-day trial
- WHEN Stripe's `trial_end` is persisted
- THEN `subscriptions.trial_ends_at` equals now+30d expressed in app timezone (local date AND time-of-day match), not now+30d+6h

### Requirement: onTrial() is false once the trial instant has passed

`Subscription::onTrial()` MUST return false from the moment the trial's real end instant passes. No skew window may exist in which an ended trial still reports as trialing.

#### Scenario: No 6-hour ghost-trial window

- GIVEN a subscription whose trial end instant has just passed
- WHEN `onTrial()` is evaluated at any point during the following 6 hours
- THEN it returns false throughout (previously the +6h skew kept it true)

### Requirement: Cancelling a non-trialing subscription stores the period end

Cancelling at period end a subscription whose trial has really ended MUST store `ends_at` equal to the current paid period end — the same instant Stripe reports as `cancel_at` — never the stale trial date.

#### Scenario: Post-trial cancel shows the paid period end

- GIVEN a subscription already charged for a full period, whose trial ended within the last 6 hours (the former ghost-trial window)
- WHEN the owner cancels at period end
- THEN `ends_at` equals Stripe's `cancel_at` (the paid period end)
- AND the subscription screen shows the period-end date, not the trial date (regression pin: a customer charged $450 for a month was told the plan ends on the trial date)

### Requirement: Existing skewed rows are corrected

Rows already carrying the +6h skew in the three columns MUST be corrected to app-timezone wall clock. Nothing is in production; no backward-compatibility path is required.

#### Scenario: Dev rows realigned

- GIVEN a pre-fix row whose `trial_ends_at` is +6h relative to the instant Stripe reports
- WHEN the one-time data correction runs
- THEN the stored value matches Stripe's instant expressed in app timezone
