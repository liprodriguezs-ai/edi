# Plan 072: Make resident-invitation email lookups case-insensitive

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 46f8a07..HEAD -- Modules/Housing/app/Services/ResidentInvitationService.php`
> If it changed since this plan was written, compare the "Current state"
> excerpts against the live code before proceeding; on a mismatch, treat it as
> a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: monorepo `48f55be`, backend submodule `46f8a07`, 2026-08-04

## Why this matters

This application stores emails verbatim — there is no mutator, no `citext`
column, and Postgres' unique index on `email` is case-sensitive. Therefore
`Juan@ejemplo.com` and `juan@ejemplo.com` are two different rows as far as the
database is concerned.

The resident-invitation flow looks accounts up with a case-sensitive
`where('email', $email)`. When an admin types the invitee's address with
different capitalization than the stored account, the lookup misses, and the
public accept flow takes the **wrong branch**: instead of "you already have an
account, log in to prove you control it", it takes "no account exists, create a
password". Whoever holds the invitation link then sets a password on a brand-new
duplicate account, and the unit membership attaches to that duplicate rather
than to the person's real account.

Two consequences: a security gate designed as defense-in-depth is bypassed, and
the resident ends up with a split identity in the cluster.

**This exact bug class is already documented in this project.** It was found and
fixed in the DataImport module, where a case-sensitive lookup could bypass the
consent gate and duplicate accounts; the fix there was `whereRaw('LOWER(email) = ?')`
(see `plans/README.md` under the ciclo-5 verification notes). The sibling
invitation service already normalizes. Only this newer flow does not.

## Current state

### The five case-sensitive lookups

```php
// backend/Modules/Housing/app/Services/ResidentInvitationService.php:69-77
    public function checkEmail(string $email, Unit $unit): array
    {
        // Match the rest of the system, which stores and compares emails verbatim
        // (case-sensitive). We only trim surrounding whitespace.
        $email = trim($email);

        $existingUser = User::withoutGlobalScope(UserTenantScope::class)
            ->where('email', $email)
            ->first();
```

⚠️ Note the comment. It states the case-sensitivity is deliberate and
consistent with "the rest of the system". **That premise is false** — see the
contrast below. The comment must be corrected as part of this plan, not left to
mislead the next reader.

```php
// backend/Modules/Housing/app/Services/ResidentInvitationService.php:102-107
        $hasPending = UserInvitation::query()
            ->where('inviteable_type', Unit::class)
            ->where('inviteable_id', $unit->id)
            ->where('tenant_id', tenant()->getTenantKey())
            ->where('email', $email)
```

```php
// backend/Modules/Housing/app/Services/ResidentInvitationService.php:211-219
        $user = $authUser
            ?? User::withoutGlobalScope(UserTenantScope::class)->where('email', $email)->first();

        // Defense-in-depth: an existing account WITH a password must prove control
        // by logging in (the authenticated confirm flow), not merely possess the
        // invitation link. The public password flow is only for new/passwordless
        // accounts. The frontend already branches on account_exists.
        if ($authUser === null && $user !== null && $user->password !== null) {
            throw new \InvalidArgumentException(
                __('Ya tienes una cuenta. Inicia sesión para unirte a la unidad.'),
                409
            );
        }
```

This is **the gate that gets bypassed**: when the lookup misses, `$user` is
`null`, the guard does not fire, and execution falls through to the
create-account branch a few lines below.

```php
// backend/Modules/Housing/app/Services/ResidentInvitationService.php:344-351
        return UserInvitation::query()
            ->where('inviteable_type', Unit::class)
            ->where('inviteable_id', $unit->id)
            ->where('tenant_id', tenant()->getTenantKey())
            ->where('unit_relationship_id', UnitRelationship::OWNER)
            ->where('email', '!=', $email)
```

(An OWNER-dedup check. Case-sensitivity here means `JUAN@x.com` and `juan@x.com`
count as two different invitees, so two OWNER invitations can be live at once.)

```php
// backend/Modules/Housing/app/Services/ResidentInvitationService.php:362-368
    private function unitHasPendingInvitationForOtherRelationship(Unit $unit, string $email, string $unitRelationshipId): bool
    {
        return UserInvitation::query()
            ...
            ->where('email', $email)
```

### The contrast — the sibling service already does it right

```php
// backend/Modules/User/app/Services/UserInvitationService.php:184
        if (mb_strtolower(trim((string) $authUser->email)) !== mb_strtolower(trim((string) $invitation->email))) {
            throw new \InvalidArgumentException(__('La invitación pertenece a otro correo.'), 403);
        }
```

### Repo conventions that apply

- Services live at `Modules/<Module>/app/Services/`, namespaced
  `Modules\<Module>\Services`.
- The established normalization idiom in this repo is
  `mb_strtolower(trim((string) $x))` for PHP-side comparison and
  `whereRaw('LOWER(email) = ?', [$normalized])` for a database lookup.
- Tests: `backend/tests/Feature/Housing/` (check whether that directory exists;
  if not, follow whatever directory the existing resident-invitation tests use —
  find them with `grep -rln "ResidentInvitation" backend/tests backend/Modules/*/tests`).

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| No other test process | `pgrep -f "artisan test"` | no output |
| Locate existing tests | `grep -rln "ResidentInvitation" backend/tests backend/Modules/*/tests` | list of files |
| Targeted tests | `cd backend && php artisan test --filter=ResidentInvitation` | all pass |
| Syntax | `cd backend && php -l <file>` | "No syntax errors detected" |
| Formatting | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Duplicate-account check (read-only) | see Step 1 | ideally zero rows |

## Scope

**In scope** (the only files you should modify or create):
- `backend/Modules/Housing/app/Services/ResidentInvitationService.php`
- `backend/tests/Feature/Housing/ResidentInvitationEmailCasingTest.php` (create —
  adjust the directory to match where the existing resident-invitation tests
  live, per the command above)

**Out of scope** (do NOT touch, even though they look related):
- `backend/Modules/User/app/Services/UserInvitationService.php` — it already
  normalizes at the point that matters. Leave it.
- Any migration adding `citext` or a functional unique index on `LOWER(email)`.
  That is the durable fix and it is a **separate, larger** change: it needs a
  data audit for existing case-duplicate rows and a decision about merging them.
  See Maintenance notes.
- A global `setEmailAttribute` mutator on the `User` model. Same reason — it
  would silently rewrite emails on every save across the whole app.
- Any other module's email handling.

## Git workflow

- Branch: `advisor/072-invitation-email-casing`
- Suggested commit: `fix(housing): compare invitation emails case-insensitively`
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Check for existing case-duplicate accounts BEFORE changing anything

Making the lookup case-insensitive means a query that previously returned zero
rows may now return **more than one**. If duplicates already exist, `->first()`
would start resolving to an arbitrary one of them.

Run this read-only query against the development database (adjust the connection
to your environment; do not run it against production without the operator's
say-so):

```sql
SELECT LOWER(email) AS normalized, COUNT(*) AS n
FROM "user"
WHERE email IS NOT NULL
GROUP BY LOWER(email)
HAVING COUNT(*) > 1;
```

Record the result in your report.

**Verify**: you have the row count. If it is greater than zero, that is a STOP
condition — see below.

### Step 2: Add a private normalization helper

Add to `ResidentInvitationService`:

```php
    /**
     * Normalize an email for comparison. The app stores emails verbatim and
     * Postgres' unique index on user.email is case-sensitive, so a raw
     * where('email', $x) silently misses an account that differs only in
     * capitalization. In this flow that miss is not cosmetic: acceptToUnit()
     * would skip the "an existing account must prove control by logging in"
     * guard and create a duplicate account instead. Mirrors
     * UserInvitationService::acceptInvitationWithAccount() and the fix already
     * applied in DataImport's RowImporter.
     */
    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
```

**Verify**: `cd backend && php -l Modules/Housing/app/Services/ResidentInvitationService.php`
→ "No syntax errors detected"

### Step 3: Convert all five lookups

Replace each case-sensitive comparison. For `User` (a table where the value is
stored verbatim), use a `LOWER()` comparison in SQL:

```php
        $existingUser = User::withoutGlobalScope(UserTenantScope::class)
            ->whereRaw('LOWER(email) = ?', [$this->normalizeEmail($email)])
            ->first();
```

Apply the same shape to:
- `:75-77` — `checkEmail()`'s user lookup
- `:106` — `checkEmail()`'s pending-invitation lookup (`UserInvitation.email`)
- `:212` — `acceptToUnit()`'s user lookup (**the security-relevant one**)
- `:350` — `unitHasPendingOwnerInvitation()`'s `!=` comparison; becomes
  `whereRaw('LOWER(email) <> ?', [...])`
- `:367` — `unitHasPendingInvitationForOtherRelationship()`'s email match

And **replace the misleading comment** at `:70-72`. It currently claims
case-sensitivity matches "the rest of the system", which is false —
`UserInvitationService` normalizes, and DataImport was fixed to normalize.
Replace it with a statement of the actual rule: emails are stored verbatim but
must always be **compared** case-insensitively.

**Verify**:
```
grep -n "where('email'" backend/Modules/Housing/app/Services/ResidentInvitationService.php
```
→ no matches.
```
grep -c "LOWER(email)" backend/Modules/Housing/app/Services/ResidentInvitationService.php
```
→ `5`.

### Step 4: Write the regression test

Create the test file (directory per the Scope note). Model its fixture on the
existing resident-invitation tests you located in the Commands table.

Cover:

1. **The security regression.** Create a user with email `Juan@Ejemplo.com` and a
   non-null `password`. Create a pending unit invitation for `juan@ejemplo.com`.
   Call the public accept endpoint with a password and that token → expect the
   **409** "Ya tienes una cuenta. Inicia sesión para unirte a la unidad." and
   assert that **no second `user` row was created** (`assertDatabaseCount` on
   `user`, or assert the count of rows matching `LOWER(email)`).

   Before this plan, this produced a duplicate account instead.

2. **`checkEmail` reports a differently-cased account as registered.** Call it
   with `JUAN@EJEMPLO.COM` for a stored `juan@ejemplo.com` → `email_registered`
   is `true`.

3. **The OWNER dedup treats differing cases as the same invitee.** With a live
   OWNER invitation for `juan@ejemplo.com`, attempt to create a second OWNER
   invitation for `JUAN@ejemplo.com` on the same unit → rejected by the existing
   dedup rule.

4. **Control case: a genuinely different email is still a different person.**
   `juan2@ejemplo.com` against a stored `juan@ejemplo.com` → treated as
   unregistered, normal creation flow proceeds. This proves the normalization
   did not over-match.

**Verify**: `cd backend && php artisan test --filter=ResidentInvitationEmailCasing`
→ all pass.

### Step 5: Confirm no regressions

```
pgrep -f "artisan test"    # must print nothing
cd backend && php artisan test --filter=ResidentInvitation
```

**Verify**: passes, with your new tests added to the count.

## Test plan

- **New tests**: `ResidentInvitationEmailCasingTest` — 4 cases as in Step 4.
- **Structural pattern**: the existing resident-invitation tests located via
  `grep -rln "ResidentInvitation" backend/tests backend/Modules/*/tests`.
- **The assertion that matters most** is case 1's *account count*, not its status
  code. A 409 with a duplicate account silently created alongside it would still
  be a failure.
- **Verification**: `php artisan test --filter=ResidentInvitation` — all pass.

## Done criteria

ALL must hold:

- [ ] `grep -c "where('email'" backend/Modules/Housing/app/Services/ResidentInvitationService.php` returns `0`
- [ ] `grep -c "LOWER(email)" backend/Modules/Housing/app/Services/ResidentInvitationService.php` returns `5`
- [ ] The `normalizeEmail()` helper exists with the explanatory docblock
- [ ] The stale "Match the rest of the system … case-sensitive" comment is gone
- [ ] `cd backend && php artisan test --filter=ResidentInvitationEmailCasing` passes
- [ ] `cd backend && php artisan test --filter=ResidentInvitation` passes
- [ ] `cd backend && vendor/bin/pint --dirty` exits 0
- [ ] Step 1's duplicate-account query result is recorded in the report
- [ ] `git -C backend status --porcelain` lists only the files in Scope
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- **Step 1's query returns any rows.** Real case-duplicate accounts already
  exist. Making the lookup case-insensitive would make `->first()` pick an
  arbitrary one, which is a different and worse failure than the current miss.
  Report the normalized addresses (not the full rows — they are PII) and stop.
  Merging duplicates is a data decision for the operator.
- **`LOWER(email)` makes a query unable to use an index and a test times out.**
  The `user` table has no functional index on `LOWER(email)`. At this
  application's scale a sequential scan is fine, but if you observe a real
  slowdown, report it rather than reverting — the fix is an index, and that is a
  migration this plan does not authorize.
- **An existing test asserts that differently-cased emails are distinct
  identities.** That would mean the behavior is depended upon somewhere. Report
  which test; do not weaken it.
- **You find further `where('email', ...)` lookups outside this service** in a
  flow that gates access. Note them in your report for a follow-up; do not
  expand this plan's scope to fix them.
- **Tests fail with `SQLSTATE 42P01` / `42703` or catalog duplicate-key errors** —
  known shared-database / seeder problems per `AGENTS.md`, not your regression.
  `pgrep -f "artisan test"`, kill stragglers, re-run once.

## Maintenance notes

- **This is a targeted fix, not the durable one.** The durable fix is at the
  schema level: either a `citext` column or a unique index on `LOWER(email)`,
  which would make the whole class of bug impossible everywhere at once. That
  requires auditing and merging any existing case-duplicate accounts first
  (Step 1 tells you whether there are any), so it is correctly a separate change.
  Recommend it to the maintainer in your report.
- **The pattern to spread**: any email lookup that gates access must be
  case-insensitive. Three places in this repo have now needed the same fix
  (DataImport's `RowImporter`, `UserInvitationService`, and now
  `ResidentInvitationService`). Worth a line in
  `.agents/skills/laravel-backend-architecture/SKILL.md` — deliberately not in this plan's scope,
  but it is what stops the fourth occurrence.
- **What a reviewer should scrutinize**: that the guard at `:218` is now actually
  reachable for differently-cased emails. That single branch is the security
  content of this change; everything else is consistency.
