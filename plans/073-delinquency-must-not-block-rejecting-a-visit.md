# Plan 073: Stop the delinquency gate from blocking a resident's ability to REJECT a visit

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 46f8a07..HEAD -- Modules/Access/app/Http/Controllers/VisitorController.php`
> If it changed since this plan was written, compare the "Current state"
> excerpt against the live code before proceeding; on a mismatch, treat it as a
> STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: monorepo `48f55be`, backend submodule `46f8a07`, 2026-08-04

## Why this matters

The delinquency-restriction feature exists to withhold **benefits** from a unit
that owes money: booking an amenity, generating a visitor QR, receiving a
package. That is a coherent product rule.

Rejecting a pending entry request is not a benefit. It is a **denial** — the
resident telling the guard "do not let this person in". The gate is currently
applied to it identically to approval, so a resident whose unit is behind on
payments receives a 403 shaped like a debt notice when they try to turn someone
away.

The practical result: an unwanted visitor sits in a pending state the resident
is not allowed to close, while the guard booth waits for a decision the system
forbids the resident from making. This is fail-closed in the wrong direction,
and unlike the other restrictions it has a physical-security dimension.

The symmetry looks mechanical rather than deliberate — the comment in `reject()`
literally says "see approve()".

## Current state

Both handlers, verbatim and adjacent in the same file:

```php
// backend/Modules/Access/app/Http/Controllers/VisitorController.php:215-234 (approve)
    {
        $visitor = Visitor::findOrFail($id);
        $this->authorizeUnitMembership($request, $visitor);

        // R-DELQ-4 hook 3: membership check FIRST — a non-member must keep getting
        // the plain AuthorizationException above, never a debt-shaped 403 (design §5).
        app(DelinquencyRestrictionService::class)->assertNotRestricted(
            $visitor->unit_id,
            DelinquencyRestrictionService::FEATURE_VISITOR_ACCESS,
        );

        $accessLog = app(AccessService::class)->approveEntry($visitor, $request->user());

        if ($accessLog === null) {
            return response()->json(['message' => 'La solicitud ya no está pendiente de aprobación.'], 422);
        }

        return response()->json(['data' => ['visitor' => $visitor->fresh(['visitor_status']), 'access_log' => $accessLog]]);
    }
```

```php
// backend/Modules/Access/app/Http/Controllers/VisitorController.php:237-256 (reject)
    /**
     * §14.4.5: resident rejects a pending entry request.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $visitor = Visitor::findOrFail($id);
        $this->authorizeUnitMembership($request, $visitor);

        // R-DELQ-4 hook 3: membership check FIRST (see approve()).
        app(DelinquencyRestrictionService::class)->assertNotRestricted(
            $visitor->unit_id,
            DelinquencyRestrictionService::FEATURE_VISITOR_ACCESS,
        );

        $deniedLog = app(AccessService::class)->rejectEntry($visitor, $request->user());

        if ($deniedLog === null) {
            return response()->json(['message' => 'La solicitud ya no está pendiente de aprobación.'], 422);
        }

        return response()->json(['data' => ['visitor' => $visitor->fresh(['visitor_status']), 'access_log' => $deniedLog]]);
    }
```

The two are byte-identical apart from `approveEntry` vs `rejectEntry` and the
variable name.

`authorizeUnitMembership()` (the line above the gate) is what actually
establishes that the caller is entitled to decide about this visitor. It stays.

### Repo conventions that apply

- Controllers in `Modules/Access` are plain Laravel controllers with
  `app(Service::class)` resolution in the custom actions. Match the surrounding
  style; do not refactor to constructor injection while you are in there.
- User-facing messages are Spanish.
- Access tests: check `backend/Modules/Access/tests/` and
  `backend/tests/Feature/Access/` — locate the existing ones with
  `grep -rln "rejectEntry\|approveEntry" backend/tests backend/Modules/Access/tests`.
  Note that Access historically had no module tests; if the directories are
  empty you are creating the first one, which is fine.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| No other test process | `pgrep -f "artisan test"` | no output |
| Locate existing tests | `grep -rln "approveEntry\|rejectEntry\|VisitorController" backend/tests backend/Modules/Access/tests` | list (may be empty) |
| Targeted tests | `cd backend && php artisan test --filter=Visitor` | all pass |
| Delinquency tests | `cd backend && php artisan test --filter=Delinquency` | all pass |
| Syntax | `cd backend && php -l <file>` | "No syntax errors detected" |
| Formatting | `cd backend && vendor/bin/pint --dirty` | exit 0 |

## Scope

**In scope** (the only files you should modify or create):
- `backend/Modules/Access/app/Http/Controllers/VisitorController.php` (the
  `reject()` method only)
- `backend/tests/Feature/Access/VisitorRejectDelinquencyTest.php` (create —
  adjust directory if the existing Access tests live elsewhere)

**Out of scope** (do NOT touch, even though they look related):
- `approve()` — the restriction there is intentional and correct. Approving a
  visitor is the benefit being withheld. Do not "make it symmetric".
- `DelinquencyRestrictionService` — no change to the service; this plan changes
  *where it is called*, not what it does.
- The other two delinquency hooks (amenities, packages). They gate benefits and
  are correct.
- `AccessService::rejectEntry()` — unchanged.
- `authorizeUnitMembership()` — must stay exactly where it is. Removing it would
  let a non-member reject someone else's visitor, which is a far worse bug than
  the one being fixed.

## Git workflow

- Branch: `advisor/073-reject-visit-delinquency`
- Suggested commit: `fix(access): let a delinquent resident still reject a pending visit`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Remove the delinquency gate from `reject()`

In `VisitorController::reject()`, delete the
`app(DelinquencyRestrictionService::class)->assertNotRestricted(...)` call and
its comment. Keep `authorizeUnitMembership($request, $visitor)` exactly as it is,
immediately after the `findOrFail`.

Replace the removed comment with one that records *why* the asymmetry is
deliberate, so nobody "restores symmetry" later:

```php
        // R-DELQ-4: deliberately NOT gated by delinquency, unlike approve().
        // Restrictions withhold BENEFITS (booking, QR, package pickup). Rejecting
        // an entry request is a DENIAL — refusing it would leave an unwanted
        // visitor pending with the resident unable to close it, and the guard
        // booth waiting on a decision the system forbids. Membership is still
        // required (line above).
```

Then check whether `DelinquencyRestrictionService` is still referenced elsewhere
in the file (it is — `approve()` uses it). If the `use` import is now unused,
remove it; if `approve()` still needs it, leave it. Verify rather than assume.

**Verify**:
```
cd backend && php -l Modules/Access/app/Http/Controllers/VisitorController.php
grep -c "assertNotRestricted" backend/Modules/Access/app/Http/Controllers/VisitorController.php
```
→ "No syntax errors detected", and the count is `1` (only `approve()` retains it).

Also confirm by reading that the remaining call is inside `approve()`, not
`reject()`.

### Step 2: Write the regression test

Create `backend/tests/Feature/Access/VisitorRejectDelinquencyTest.php`.

If Access has existing feature tests, model the fixture on them. If it does not,
model on `backend/tests/Feature/Collections/DelinquencyRestrictionServiceTest.php`
(or wherever `--filter=Delinquency` finds tests) for how to put a unit into the
`OVERDUE` state and enable the visitor-access restriction setting, and on any
existing visitor test for the request shape.

Cover:

1. **The fix.** A unit in `OVERDUE` with the visitor-access restriction enabled;
   an active member of that unit; a visitor in `PENDING_APPROVAL`. Call the
   reject endpoint → **2xx**, and assert the visitor's status moved to the
   rejected/denied state in the database and an `access_log` denial row exists.
2. **Approval is still blocked.** Same fixture, call the approve endpoint →
   **403**. This is the control case proving the restriction still works where
   it should; without it, a future refactor could delete both gates and this
   suite would stay green.
3. **Membership is still required.** A user who is **not** a member of the unit
   calls reject → the plain authorization failure (403 from
   `authorizeUnitMembership`, not a debt-shaped error), and the visitor's status
   is unchanged.
4. **A non-delinquent unit can still reject.** Baseline sanity.

**Verify**: `cd backend && php artisan test --filter=VisitorRejectDelinquency`
→ all pass.

### Step 3: Confirm no regressions

```
pgrep -f "artisan test"    # must print nothing
cd backend && php artisan test --filter=Visitor
cd backend && php artisan test --filter=Delinquency
```

Run them one at a time.

**Verify**: both pass.

## Test plan

- **New tests**: `VisitorRejectDelinquencyTest` — 4 cases (reject allowed while
  delinquent; approve still blocked; non-member still rejected; non-delinquent
  baseline).
- **Structural pattern**: the existing delinquency tests for the OVERDUE fixture
  (`--filter=Delinquency`), plus any existing visitor test for the endpoint call
  shape.
- **Why case 2 is non-negotiable**: it is the only thing distinguishing "removed
  the gate from reject" from "removed the gate". Without it, an over-eager
  cleanup could delete both and nothing would fail.
- **Verification**: `php artisan test --filter=Visitor` and `--filter=Delinquency`,
  run separately, all green.

## Done criteria

ALL must hold:

- [ ] `grep -c "assertNotRestricted" backend/Modules/Access/app/Http/Controllers/VisitorController.php` returns `1`
- [ ] Reading the file confirms the remaining call is inside `approve()`
- [ ] `reject()` still calls `authorizeUnitMembership($request, $visitor)`
- [ ] The explanatory comment about the deliberate asymmetry is present in `reject()`
- [ ] `cd backend && php artisan test --filter=VisitorRejectDelinquency` passes
- [ ] `cd backend && php artisan test --filter=Visitor` passes
- [ ] `cd backend && php artisan test --filter=Delinquency` passes
- [ ] `cd backend && vendor/bin/pint --dirty` exits 0
- [ ] `git -C backend status --porcelain` lists only the files in Scope
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **An existing test asserts that a delinquent resident gets a 403 on reject.**
  That would mean the behavior was specified, not accidental. Report the test and
  the spec reference (`§14.4.5` / the R-DELQ design doc) and stop — this becomes
  a product decision, not a bug fix.
- **`authorizeUnitMembership()` turns out not to cover the reject path** (for
  example if the route uses a different middleware). Removing the delinquency
  call must not be the only thing standing between a stranger and someone else's
  visitor. Verify membership enforcement before removing anything; if it is
  absent, stop and report — that is a bigger finding.
- **`reject()` has gained other logic** since this plan was written that depends
  on the delinquency call having run. Read it fully before deleting.
- **Tests fail with `SQLSTATE 42P01` / `42703` or catalog duplicate-key errors** —
  known shared-database / seeder problems per `AGENTS.md`. `pgrep -f "artisan
  test"`, kill stragglers, re-run once.

## Maintenance notes

- **The principle worth writing down** (and the reason for the comment in Step 1):
  a delinquency restriction may only gate an action that *grants* something to
  the unit. Actions that deny, cancel, or close must stay reachable. If a fourth
  restriction hook is added later, apply that test to it.
- **What a reviewer should scrutinize**: that `authorizeUnitMembership` is still
  the first thing `reject()` does. The security of this endpoint rests entirely
  on it now.
- **Adjacent, deliberately not done here**: the frontend may still hide or
  disable the reject button when it knows the unit is restricted (it reads
  `/my-restrictions`). If it does, the backend fix alone will not surface the
  button. Check `frontend/src/modules/access` for a restriction-driven disable on
  the reject action and report it — fixing that is a small frontend follow-up,
  but it is in the other submodule and is not in this plan's scope.
- **Cosmetic option not taken**: returning the delinquency state as an
  informational field on the reject response (so the UI can still nudge about the
  debt) would preserve the product's messaging without blocking the action.
  Mentioned for the maintainer; not implemented.
