# Plan 077: Close the Complaint module's confidentiality leak and its unlocked state transition

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> ```
> git -C backend diff --stat 46f8a07..HEAD -- Modules/Complaint
> ```
> On any change, compare against the "Current state" excerpts; on a mismatch,
> treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: S (part A and C) / S–M (part B, gated on a product decision)
- **Risk**: LOW for A and C; MED for B (tightens a read scope)
- **Depends on**: none. Pairs naturally with plan 069, which locks down the same
  module's Orion update route — but neither blocks the other.
- **Category**: security / bug
- **Planned at**: monorepo `48f55be`, backend submodule `46f8a07`, 2026-08-04

## Why this matters

The Complaint module ("Incidencias") is built around one confidentiality rule:
a manager who is linked to the unit a complaint is filed **against** must not see
that complaint. The module implements this rule in two places that do not agree,
and leaves one endpoint with no check at all.

**A — one endpoint skips the check entirely.** `eligibleAssignees` resolves the
complaint by raw id with no visibility scope. An excluded manager — precisely
the person the rule exists to shut out — can confirm the complaint exists and
read back which managers were *not* excluded from it. Since exclusion is derived
from unit linkage, that list leaks who is linked to the denounced unit. It is the
exact inference the design is meant to prevent, and the controller's own docblock
states the principle being violated.

**B — the tenant-configurable `manager_role_keys` setting does not apply to the
main read path.** It gates the message thread and the history, but not the list
or the detail. A cluster that narrows "who manages incidencias" gets a setting
that silently does less than its name implies.

**C — the state machine is validated against an unlocked, stale row.** Two
managers acting at the same time can both pass `isValidTransition` and both
write, producing an audit entry for a transition that was never legal from the
row's real state. The module notifies every manager on creation and shows them
all the same buttons, so simultaneous action is a normal scenario, not a
contrived one. The sibling module written in the same release does this
correctly, which makes the divergence easy to fix by imitation.

## Current state

### A — `eligibleAssignees` has no visibility check

```php
// backend/Modules/Complaint/app/Http/Controllers/ComplaintController.php:170-179
    public function eligibleAssignees(Request $request, string $complaintId): JsonResponse
    {
        $this->authorize('read.complaint', Complaint::class);

        $complaint = Complaint::findOrFail($complaintId);

        $assignees = app(ComplaintService::class)->eligibleAssignees($complaint);

        return response()->json(['data' => $assignees]);
    }
```

Contrast — the same controller gets it right everywhere else:

```php
// backend/Modules/Complaint/app/Http/Controllers/ComplaintController.php:50-62
    protected function buildIndexFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildIndexFetchQuery($request, $requestedRelations);

        return $query->visibleTo($request->user());
    }

    protected function buildShowFetchQuery(Request $request, array $requestedRelations): Builder
    {
        $query = parent::buildShowFetchQuery($request, $requestedRelations);

        return $query->visibleTo($request->user());
    }
```

and states the rule explicitly:

```php
// backend/Modules/Complaint/app/Http/Controllers/ComplaintController.php:64-68
    /**
     * Confidential exclusion (§6), continued: update/destroy/restore MUST apply
     * the same visibleTo() scope as index/show, or an excluded manager could
     * mutate a complaint they cannot even see by guessing its id.
     */
```

`messages()` (`:225-245`) and `history()` (`:209-220`) additionally call
`assertVisible`.

### B — the two gates diverge

```php
// backend/Modules/Complaint/app/Models/Complaint.php:96-112
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $excludedUnitIds = UnitUser::where('user_id', $user->id)
            ->whereNull('end_date')
            ->pluck('unit_id')
            ->all();

        if ($excludedUnitIds === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($excludedUnitIds, $user) {
            $q->whereNull('against_unit_id')
                ->orWhereNotIn('against_unit_id', $excludedUnitIds)
                ->orWhere('reporter_user_id', $user->id);
        });
    }
```

Unit linkage only. It never consults `managerRoleKeys()`.

```php
// backend/Modules/Complaint/app/Services/ComplaintMessageService.php:77-90
    public function assertVisible(Complaint $complaint, User $user): void
    {
        if ($user->id === $complaint->reporter_user_id || $user->id === $complaint->assigned_user_id) {
            return;
        }

        if (! $user->hasAnyRole(ComplaintAccess::managerRoleKeys())) {
            abort(403, 'No tienes acceso a esta incidencia');
        }

        if (in_array($user->id, ComplaintAccess::excludedUserIds($complaint->against_unit_id), true)) {
            abort(403, 'No puedes acceder a una incidencia vinculada a tu propia unidad');
        }
    }
```

Role check **and** exclusion.

```php
// backend/Modules/Complaint/app/Support/ComplaintAccess.php:18-29
    private const DEFAULT_MANAGER_ROLE_KEYS = ['president', 'treasurer', 'secretary', 'vocal', 'oversight_committee', 'administrator'];

    public static function managerRoleKeys(): array
    {
        $configured = Setting::getValue('complaint.manager_role_keys');

        return is_array($configured) && $configured !== [] ? $configured : self::DEFAULT_MANAGER_ROLE_KEYS;
    }
```

With the **default** value the two gates agree, because `read.complaint` is
granted to exactly those six roles (`RolePermissionTableSeeder.php:393,445,464,486,522`;
residents and renters correctly do not hold it). The divergence only becomes
observable once a tenant narrows the setting.

### C — the transition runs its guards outside the transaction

```php
// backend/Modules/Complaint/app/Services/ComplaintService.php:232-244
    public function changeStatus(Complaint $complaint, string $toStatusId, User $actor): Complaint
    {
        $this->assertManagerNotExcluded($complaint, $actor);

        if (! $this->isValidTransition($complaint->complaint_status_id, $toStatusId)) {
            abort(422, 'Transición de estado no permitida');
        }

        return DB::transaction(function () use ($complaint, $toStatusId): Complaint {
            $complaint->complaint_status_id = $toStatusId;
            $complaint->resolved_at = in_array($toStatusId, ComplaintStatus::TERMINAL, true) ? now() : null;
            $complaint->save();
```

The instance comes from `ComplaintController::transition` via a plain
`Complaint::findOrFail($complaintId)` (`:118`) — no lock, no re-read inside the
transaction. `assign()` (`:273-302`) has the same shape.

The house pattern, from the module written alongside it:

```php
// backend/Modules/AmenityReservations/app/Services/ReservationService.php:979-983
    private function lockReservation(string $id): AmenityReservation
    {
        return AmenityReservation::where('id', $id)->lockForUpdate()->firstOrFail();
    }
```

used inside every transition's transaction (`:189-213`, `:235-248`, `:266-298`,
`:411-423`, `:435-447`).

### Repo conventions that apply

- Scopes live on the model (`scopeVisibleTo`); shared access helpers in
  `Modules/Complaint/app/Support/ComplaintAccess.php`.
- Error messages are Spanish.
- Complaint tests are central: `backend/tests/Feature/Complaint/`. Existing files
  include `ComplaintExclusionTest.php` — read it first; it is the fixture pattern
  for exclusion scenarios.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| No other test process | `pgrep -f "artisan test"` | no output |
| Module tests | `cd backend && php artisan test --filter=Complaint` | all pass |
| Syntax | `cd backend && php -l <file>` | "No syntax errors detected" |
| Formatting | `cd backend && vendor/bin/pint --dirty` | exit 0 |

## Scope

**In scope**:
- `backend/Modules/Complaint/app/Http/Controllers/ComplaintController.php` (part A)
- `backend/Modules/Complaint/app/Services/ComplaintService.php` (part C)
- `backend/Modules/Complaint/app/Models/Complaint.php` (part B — **only if the
  product decision in Step 2 says yes**)
- `backend/tests/Feature/Complaint/ComplaintAccessAndTransitionTest.php` (create)

**Out of scope** (do NOT touch, even though they look related):
- `ComplaintMessageService::assertVisible` — it is the correct, stricter gate.
  It is the model being copied, not changed.
- `ComplaintAccess` — no change to how roles or exclusions are resolved.
- The Orion `update` route's field validation — that is plan 069.
- `ComplaintHistoryService::decodeValues` — that workaround belongs to plan 075.
- The notification wiring on transitions.

## Git workflow

- Branch: `advisor/077-complaint-access-and-fsm`
- Suggested commits (one per part):
  - `fix(complaint): scope eligible-assignees by the same visibility rule as show`
  - `fix(complaint): validate status transitions against a locked row`
  - `fix(complaint): apply manager_role_keys to the list and detail read paths` (only if Step 2 approves)
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1 (part A): Scope `eligibleAssignees`

Change the resolution in `ComplaintController::eligibleAssignees` to apply the
same visibility scope the show path uses, and add the thread-level assertion the
sibling custom routes use:

```php
        $complaint = Complaint::visibleTo($request->user())->findOrFail($complaintId);

        app(ComplaintMessageService::class)->assertVisible($complaint, $request->user());
```

Using `findOrFail` on the scoped query means an excluded manager gets a 404 —
they cannot even confirm the complaint exists, which is the point. Add the
`ComplaintMessageService` import if it is not already there, and a one-line
comment naming §6 so the reason survives.

**Verify**:
```
cd backend && php -l Modules/Complaint/app/Http/Controllers/ComplaintController.php
grep -n "visibleTo" backend/Modules/Complaint/app/Http/Controllers/ComplaintController.php
```
→ no syntax errors; `visibleTo` now appears in `eligibleAssignees` as well as the
three `build*FetchQuery` methods.

### Step 2 (part B): ASK before changing the read scope — do not decide alone

Extending `scopeVisibleTo` to also require manager-role membership would hide
complaints from roles that can see them today. Whether `read.complaint` or
`complaint.manager_role_keys` is authoritative is a **product** question, not an
engineering one.

Gather the evidence and put the question to the operator:

1. Check how the setting is labelled in the config UI — is it "who can see
   incidencias" or "who can act on them"?
   `grep -rn "manager_role_keys" frontend/src` and read the surrounding label.
2. Report which roles hold `read.complaint` today (the seeder lines cited above).
3. Report that with the default setting value the two gates agree, so this only
   bites tenants who narrowed it.

**If the operator says the setting should govern reads**, extend `scopeVisibleTo`
to mirror `assertVisible` exactly: reporter ∨ assignee ∨ (has a manager role ∧
not excluded). Mirror it structurally so the two cannot drift — ideally by
having both call one predicate in `ComplaintAccess`.

**If the operator says no, or does not answer**, skip this part, record it as
deliberately deferred in your report, and continue to Step 3. Parts A and C stand
on their own.

**Verify**: either the change is made and the new tests in Step 4 cover it, or
your report states clearly that part B was deferred and why.

### Step 3 (part C): Validate transitions against a locked row

In `ComplaintService::changeStatus`, move both guards **inside** the transaction
and run them against a locked re-read. Add a private helper mirroring
`ReservationService::lockReservation`:

```php
    /**
     * Re-read the complaint under a row lock. Every guard must run against the
     * locked copy: two managers act on the same complaint concurrently (the
     * module notifies all of them on creation and shows them the same buttons),
     * and validating a stale instance lets both pass isValidTransition and both
     * write. Mirrors ReservationService::lockReservation().
     */
    private function lockComplaint(string $id): Complaint
    {
        return Complaint::where('id', $id)->lockForUpdate()->firstOrFail();
    }
```

Restructure `changeStatus` so the transaction opens first, then locks, then runs
`assertManagerNotExcluded` and `isValidTransition` against the locked instance,
then writes. Apply the identical restructuring to `assign()`.

Keep the 422 message text exactly as it is.

**Verify**:
```
cd backend && php -l Modules/Complaint/app/Services/ComplaintService.php
grep -n "lockForUpdate" backend/Modules/Complaint/app/Services/ComplaintService.php
```
→ present. Then read both methods and confirm no guard remains above the
`DB::transaction(` line.

### Step 4: Write the tests

Create `backend/tests/Feature/Complaint/ComplaintAccessAndTransitionTest.php`,
modelled on `backend/tests/Feature/Complaint/ComplaintExclusionTest.php`
(read it first for the tenant/user/complaint/exclusion fixture).

Cases:

1. **(A) An excluded manager gets 404 from `eligible-assignees`.** A manager who
   is an active member of the complaint's `against_unit_id` calls
   `GET /api/complaint/{id}/eligible-assignees` → **404** (not 403 — the point is
   that existence is not confirmed).
2. **(A) A non-excluded manager still gets the list.** Control case: the endpoint
   must keep working for its intended users, returning a non-empty array.
3. **(C) The FSM rejects an illegal transition** — re-assert the existing rule
   from the new, locked code path.
4. **(C) The lock is issued.** With `DB::listen()`, capture queries during a
   transition and assert a `select ... from "complaint" ... for update` ran
   inside the transaction, before the update. This is what fails if someone
   moves the guards back out.
5. **(C) `assign()` rejects an excluded assignee**, exercised through the locked
   path.
6. **(B, only if Step 2 approved)** With `complaint.manager_role_keys` narrowed to
   exclude a role that holds `read.complaint`, a user with that role gets an
   empty list from `GET /api/complaint` and a 404 on show; and the reporter and
   assignee escape hatches still work.

**Verify**: `cd backend && php artisan test --filter=ComplaintAccessAndTransition`
→ all pass.

### Step 5: Regression check

```
pgrep -f "artisan test"
cd backend && php artisan test --filter=Complaint
```

**Verify**: passes, with your new tests added to the count.

## Test plan

- **New tests**: `ComplaintAccessAndTransitionTest` — 5 cases, or 6 if part B was
  approved.
- **Structural pattern**: `backend/tests/Feature/Complaint/ComplaintExclusionTest.php`.
- **Case 2 is the control for part A** (the endpoint must still serve its real
  users) and **case 4 is the mechanism assertion for part C** (a behavioral test
  alone cannot distinguish "locked" from "got lucky on ordering").
- **Honest limitation**: case 4 asserts the lock is taken, not that two real
  concurrent transitions serialize. Say so in your report.
- **Verification**: `php artisan test --filter=Complaint` — all pass.

## Done criteria

ALL must hold:

- [ ] `eligibleAssignees` resolves via `Complaint::visibleTo($request->user())->findOrFail(...)` and calls `assertVisible`
- [ ] `grep -c "lockForUpdate" backend/Modules/Complaint/app/Services/ComplaintService.php` returns `1` or more
- [ ] In both `changeStatus` and `assign`, no guard runs above the `DB::transaction(` line (verify by reading)
- [ ] Part B is either implemented **or** explicitly recorded in the report as deferred with the operator's answer
- [ ] `cd backend && php artisan test --filter=ComplaintAccessAndTransition` passes
- [ ] `cd backend && php artisan test --filter=Complaint` passes
- [ ] `cd backend && vendor/bin/pint --dirty` exits 0
- [ ] `git -C backend status --porcelain` lists only files from the Scope section
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **You cannot get an answer on part B.** Do not decide it yourself. Ship A and C,
  record B as deferred. Tightening a read scope without a product decision can
  hide records from people who currently rely on seeing them.
- **An existing test asserts that `eligible-assignees` returns 200 for an
  excluded manager.** That would mean the behavior was intended. Report the test
  and stop part A.
- **`assertVisible` turns out to reject a legitimate caller of
  `eligible-assignees`** — for example a non-manager who is nonetheless allowed
  to assign. Read who actually calls that endpoint in the frontend
  (`grep -rn "eligible-assignees" frontend/src`) before assuming. Report and stop
  if there is a conflict.
- **Moving the guards inside the transaction changes which HTTP status a rejected
  transition returns.** `abort(422)` inside a transaction still returns 422, but
  confirm it with case 3 rather than assuming; a wrapped exception that becomes a
  500 would be a regression.
- **Tests fail with `SQLSTATE 42P01` / `42703` or catalog duplicate-key errors** —
  known shared-database / seeder problems per `AGENTS.md`. `pgrep`, kill, re-run
  once.

## Maintenance notes

- **The durable fix for the A/B class of bug** is to have exactly one predicate
  that answers "can this user see this complaint", used by the scope, by
  `assertVisible`, and by every custom route. Today there are two
  implementations that agree only by coincidence of configuration. If part B is
  approved, do the consolidation as part of it; if not, note in
  `ComplaintAccess` that the two gates are deliberately different and why.
- **What a reviewer should scrutinize**: that `eligibleAssignees` returns 404 and
  not 403. A 403 still confirms the row exists, which is most of the leak.
- **Deliberately not addressed**: `scopeVisibleTo` applies no exclusion when
  `against_unit_id` is null — the model's own docblock calls this a documented v1
  limitation. It is unchanged here.
- **Pairs with plan 069**: that plan stops the Orion update route from writing
  `complaint_status_id` directly, which is the other way to bypass the FSM this
  plan is locking. Landing both is what actually makes the state machine
  authoritative.
