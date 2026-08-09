# Plan 080: Decide and specify how AmenityReservations gets turned on (design spike, not a build)

> **Executor instructions**: This is a **design and decision** plan, not an
> implementation plan. Its deliverable is a written recommendation plus a
> ready-to-execute build spec — **not code**. Do not write application code.
> If you find yourself editing anything under `backend/Modules/` or
> `frontend/src/`, stop: you have left this plan's scope. When done, update the
> status row in `plans/README.md`.
>
> **Drift check (run first)**:
> ```
> git -C backend diff --stat 46f8a07..HEAD -- Modules/AmenityReservations Modules/Configuration/routes/api.php
> git -C frontend diff --stat 7d4e05e..HEAD -- src/modules/configuration
> ```
> On any change, re-verify the facts in "Current state" before writing your
> recommendation.

## Status

- **Priority**: P2
- **Effort**: M (spike: S–M; the build it specifies: M)
- **Risk**: LOW (this plan writes no code)
- **Depends on**: none
- **Category**: direction
- **Planned at**: monorepo `48f55be`, backend `46f8a07`, frontend `7d4e05e`, 2026-08-04

## Why this matters

AmenityReservations is the largest single piece of work in this release — 101
backend files, 26 test files, three frontend module clusters, integration with
the Collections FIFO payment engine, a surcharge service, an expiry cron and five
email templates. All of it is gated behind one per-tenant setting that defaults
to `false` and that **no product surface can turn on**.

There is no `config/amenity-settings` route and no `AmenityConfigSection`. The
module's own settings class calls out "the future config controller" that was
never written. Activation today means a manual `UPDATE setting SET value='true'`
or a tinker call, per tenant.

So the remaining work between "built" and "shipped" is small — but it is blocked
on a question engineering should not answer alone: **is this a cluster-admin
toggle, or a platform/plan entitlement?** Building the wrong one is more
expensive than deciding first, which is why this is a spike.

## Current state — the verified facts your recommendation must build on

### The flag

```php
// backend/Modules/AmenityReservations/app/Support/AmenityReservationsSettings.php
/**
 * Central reference for the AmenityReservations per-tenant kill-switch.
 *
 * `amenity_reservations.enabled` is a Setting slug (tenant-scoped key-value)
 * that gates the whole module for a tenant, independent from menu visibility
 * (S17) and from the module's own permission set (S2). Defaults to `false` —
 * the module stays dormant until the GA slice explicitly flips it on for a
 * tenant.
 *
 * Keeping the slug and default here (mirroring Collections\Support\CollectionSettings)
 * avoids drift between the seeder, the future config controller, and any
 * service/middleware that checks the flag.
 */
final class AmenityReservationsSettings
{
    public const ENABLED = 'amenity_reservations.enabled';

    public const DEFAULT_ENABLED = false;
}
```

Note the phrases "until the GA slice explicitly flips it on" and "the future
config controller". The original design anticipated exactly this gap.

### Who can currently reach the module

```php
// backend/Modules/AmenityReservations/app/Http/Middleware/EnsureAmenityReservationsEnabled.php:40-58
        if ($user && $user->hasRole([Role::SLUG_SUPER_ADMIN, Role::SLUG_LAWYER])) {
            return $next($request);
        }

        // No tenant initialized: nothing to gate against, let it through
        // (defensive — every route in this group already runs initializeTenancy).
        if (! tenancy()->initialized) {
            return $next($request);
        }

        if (Setting::getValue(AmenityReservationsSettings::ENABLED) === true) {
            return $next($request);
        }

        return response()->json([
            'message' => 'El módulo de amenidades no está habilitado para este clúster.',
            'code' => 'AMENITY_RESERVATIONS_DISABLED',
        ], 403);
```

**This matters for your recommendation**: the module is *not* dark for everyone.
Super admin and lawyer already bypass the gate, so platform staff can exercise it
today. It is dark for **tenants**.

### Readers of the flag (all read, none write)

- `app/Http/Middleware/EnsureAmenityReservationsEnabled.php:51`
- `app/Handlers/AmenityReservationEarmarkHandler.php:45`
- `app/Console/Commands/CancelExpiredReservationsCommand.php:74`
- `database/seeders/AmenityReservationsDatabaseSeeder.php:32` — seeds it `false`
- 17 test files flip it with raw `Setting::where('slug', …)->update(...)`

### There IS a raw write path — do not build on it

`Orion::resource('setting', SettingController::class)` exposes generic CRUD, and
`update.setting` is not in `$permissionsOnlySuperAdmin`, so account owners hold
it. That route is a validation bypass around every module's settings endpoint and
**plan 069 makes it read-only.** Assume it will be gone; do not design activation
around it.

### The shape to copy for a Configuration-section approach

Backend routes:

```php
// backend/Modules/Configuration/routes/api.php:58-68
        // Package settings (package-policies spec — reuses read/update.configuration)
        Route::get('config/package-settings', [ConfigurationController::class, 'getPackageSettings']);
        Route::put('config/package-settings', [ConfigurationController::class, 'updatePackageSettings']);

        // Complaint settings (complaints design §10 — reuses read/update.configuration)
        Route::get('config/complaint-settings', [ConfigurationController::class, 'getComplaintSettings']);
        Route::put('config/complaint-settings', [ConfigurationController::class, 'updateComplaintSettings']);

        // Delinquency restriction settings (reuses read/update.configuration)
        Route::get('config/delinquency-settings', [ConfigurationController::class, 'getDelinquencySettings']);
        Route::put('config/delinquency-settings', [ConfigurationController::class, 'updateDelinquencySettings']);
```

Frontend registration — one entry per module:

```tsx
// frontend/src/modules/configuration/configSections.tsx:122-131
  {
    key: 'packages',
    label: 'Paquetería',
    icon: Package2,
    title: 'Paquetería',
    description:
      'Configura cómo el condominio gestiona los paquetes en portería: notificaciones de llegada, código de retiro QR, firma y evidencia requeridas, y tiempos de retención antes del recordatorio y devolución.',
    render: ({ canUpdate }) => <PackageConfigSection canUpdate={canUpdate} />,
  },
```

### The project has a dedicated tool for this exact shape

`AGENTS.md` documents the `module-config` skill and the
`@module-config-scaffolder` agent, whose stated job is: the `Setting` rows,
`getX/updateXSettings` on `ConfigurationController`, the `config/x-settings`
routes, defaults seeded on `TenantCreated`, and one `XConfigSection` entry in
`configSections.tsx`. If the decision lands on "Configuration section", the build
should go through that skill rather than being hand-written.

## The question to answer

**Is `amenity_reservations.enabled` a cluster-admin toggle or a platform/plan
entitlement?**

The evidence points both ways, which is exactly why it needs a decision:

- **Toward a tenant toggle**: it is a per-tenant `Setting` row, seeded per tenant,
  and every other module's operational configuration (`collection`, `package`,
  `complaint`, `delinquency`) is a Configuration-screen section. Consistency
  argues for the same shape.
- **Toward a platform entitlement**: it defaults to `false`, the docblock frames
  it as a GA rollout switch ("stays dormant until the GA slice explicitly flips
  it on"), and super admin already bypasses it. A tenant toggle that defaults off
  is a strange product: nobody discovers a feature by finding its off switch. It
  is also the only module gated this way — the others gate on
  `moduleEnabled` (billing entitlement), which is a different mechanism the
  codebase already has.

A third possibility worth costing: **both** — a platform entitlement that decides
whether the cluster *may* use amenities, plus a tenant-facing section for the
module's operational settings once enabled. That is how a paid add-on usually
behaves, and there is prior art in this repo (the billing addons work).

## Commands you will need

Read-only investigation only.

| Purpose | Command |
|---|---|
| Find the billing-entitlement mechanism | `grep -rn "moduleEnabled\|billable_module\|VerifyModuleEnabled" backend/ --include='*.php' \| head -30` |
| See how a billable module is gated | `grep -rn "moduleEnabled" backend/Modules/*/routes/api.php` |
| Menu visibility wiring | `grep -rn "amenity" backend/database/seeders/csv/*.csv` |
| Check the frontend's module gating | `grep -rn "AMENITY_RESERVATIONS_DISABLED\|amenity" frontend/src/shared --include='*.ts' \| head` |
| Confirm no config route exists | `grep -rn "amenity" backend/Modules/Configuration/routes/api.php` |
| Existing config sections | `sed -n '1,60p' frontend/src/modules/configuration/configSections.tsx` |

## Scope

**In scope** — you may create exactly these two files:
- `plans/080-findings.md` — your investigation write-up and recommendation
- `plans/081-amenity-activation-build.md` — the build plan your recommendation
  implies, written to the same template as the other plans in this directory
  (read `plans/069-orion-unvalidated-writes.md` as a format reference)

**Out of scope** (do NOT do any of this):
- **Any change under `backend/` or `frontend/`.** No code, no routes, no
  components, not even a comment.
- Flipping the flag for any tenant in any environment.
- Deciding the answer yourself if the operator is reachable — see Step 4.
- Building the config section "while you're at it".

## Steps

### Step 1: Establish what the alternative mechanism actually is

The codebase already has a billing-entitlement gate (`moduleEnabled` /
`VerifyModuleEnabled` / `billable_module`) used by other modules. Read it and
document:

- how a module is marked entitled for a tenant,
- who can grant it (super admin only? a Stripe subscription item?),
- what the resident/admin sees when a module is not entitled,
- whether an amenity entitlement would need a new `billable_module` row and a
  Stripe price, or whether it can be free-but-gated.

**Verify**: your write-up names the exact files and the grant path.

### Step 2: Establish the cost of each option

For each of the three options, list the concrete work:

- **(a) Tenant toggle via Configuration section** — controller methods, routes,
  the React section, seeded defaults, permission reuse (`read/update.configuration`),
  and the `module-config` skill invocation that would build it.
- **(b) Platform/plan entitlement** — whether it reuses `moduleEnabled` wholesale,
  what changes in `EnsureAmenityReservationsEnabled`, whether the `Setting` row
  goes away or stays as a secondary switch, and what the billing implications are.
- **(c) Both** — entitlement for "may use", section for "how it behaves".

Give each a coarse effort (S/M/L) and name the files each would touch.

**Verify**: three options, each with a file list and an effort estimate.

### Step 3: Identify the operational settings the module needs beyond `enabled`

Even under option (b), the module may need tenant-facing configuration. Read the
module for settings-shaped constants and decisions that an admin would plausibly
want to control — start from `ReservationService`'s constants (e.g.
`DEFAULT_PAYMENT_DEADLINE_DAYS`), the cron's reminder window, and anything the
`amenity` table does **not** already carry per-amenity.

Be strict: something already configurable per amenity (cost, deposit, capacity,
weekdays, approval) is **not** a cluster setting. Only list what is genuinely
cluster-wide.

**Verify**: a list of candidate settings with, for each, one line on why it is
cluster-wide rather than per-amenity. An empty or near-empty list is a legitimate
outcome and is itself useful — it would mean option (b) needs no section at all.

### Step 4: Put the decision to the operator

Present the three options with their costs and your recommendation, and **ask**.
Do not choose for them.

Frame the recommendation on the evidence, and state the strongest argument
against your own recommendation. If the operator is unreachable, write the
recommendation, mark the decision as **pending**, and stop — do not write plan
081 against an assumed answer.

**Verify**: either you have a recorded decision, or `plans/080-findings.md` ends
with an explicit "PENDING OPERATOR DECISION" and no build plan was written.

### Step 5: Write the build plan for the chosen option

Only after Step 4 has an answer. Write `plans/081-amenity-activation-build.md`
following the same structure as the other plans here: Status, Why this matters,
Current state with real excerpts you opened yourself, Commands, Scope
(in/out), Git workflow, numbered Steps each with a verification command, Test
plan, machine-checkable Done criteria, STOP conditions, Maintenance notes.

If the answer is option (a) or (c), the build plan's steps should **invoke the
`module-config` skill** and delegate to `@module-config-scaffolder` rather than
hand-specifying the boilerplate — that is what those exist for. The build plan's
own value is the manifest: which settings, what types, what defaults, what
Spanish labels and help text, and who may edit them.

**Verify**: `plans/081-amenity-activation-build.md` exists and satisfies the
quality bar in `~/.claude/skills/improve/references/plan-template.md` (a model
that has never seen this repo could execute it from the file plus the repo).

### Step 6: Register both files in the index

Add rows to `plans/README.md` for 080 (this spike, DONE) and 081 (the build,
TODO), and record the decision in the ciclo-6 "Direction options" section so the
reasoning is not lost.

**Verify**: `grep -n "080\|081" plans/README.md` → both present.

## Test plan

This plan produces documents, not code, so there is nothing to test.

The quality gate is instead: **the build plan you write must be executable by a
model with no context**. Before declaring done, re-read
`plans/081-amenity-activation-build.md` and check it against the quality bar in
`~/.claude/skills/improve/references/plan-template.md` — especially that every
code excerpt in it came from a file you opened yourself, and that every step ends
in a command with an expected result.

## Done criteria

ALL must hold:

- [ ] `plans/080-findings.md` exists and covers Steps 1–3 with real file references
- [ ] Three options are costed, each with a file list and an S/M/L estimate
- [ ] The operator's decision is recorded — or the file ends with an explicit "PENDING OPERATOR DECISION"
- [ ] If a decision exists: `plans/081-amenity-activation-build.md` exists and meets the plan-template quality bar
- [ ] If no decision exists: no build plan was written, and that is stated
- [ ] `git -C backend status --porcelain` is **empty** (no backend changes)
- [ ] `git -C frontend status --porcelain` is **empty** (no frontend changes)
- [ ] `plans/README.md` has rows for 080 and 081
- [ ] The flag was **not** flipped in any environment

## STOP conditions

Stop and report back (do not improvise) if:

- **You are about to write application code.** This plan produces documents. If
  the build feels trivial enough to just do, that is precisely the trap — the
  decision, not the code, is what is blocking.
- **The operator is unreachable.** Write the findings, mark the decision pending,
  and stop after Step 4. Do not guess.
- **Step 1 reveals that `moduleEnabled` already covers amenities** (i.e. there is
  already a `billable_module` row for it). Then the `Setting` flag may be
  redundant and the real question changes to "which of the two gates wins".
  Report that immediately; it would make option (b) nearly free.
- **Step 3 finds that the module needs a substantial settings surface**
  (say, more than five cluster-wide settings). Then option (a) or (c) is
  effectively mandatory regardless of the entitlement question, and the effort
  estimate rises. Say so explicitly.
- **You discover the module is already enabled for some tenant.** That would mean
  someone activated it manually. Report which tenants and how, before recommending
  anything — the rollout may already be under way.

## Maintenance notes

- **Why this is a spike and not a build**: the playbook this plan came from is
  explicit that direction findings become design plans, not build-everything
  plans. The engineering here is genuinely small; the expensive mistake would be
  building a tenant toggle for something that should be a paid entitlement, or
  vice versa, and then migrating.
- **Whatever is decided, plan 069 should land first or alongside.** It makes the
  generic `setting` resource read-only, which removes the current unofficial
  activation path. If someone is relying on that path today, they need the
  official one before it closes.
- **The 17 tests that flip the flag with raw `Setting::where(...)->update(...)`**
  will keep working under any option, but if activation moves to an entitlement
  they become misleading. Note them in the build plan so they get updated to
  whatever the real mechanism becomes.
- **Do not let this sit as "pending" indefinitely.** Every week the module stays
  dark is a week of paid-for work delivering nothing, and the code keeps aging
  against the rest of the codebase.
