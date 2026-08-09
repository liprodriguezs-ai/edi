# Plan 056: Stop DataImport from silently enrolling existing accounts into another tenant

> **Executor instructions**: Follow step by step; verify each step. STOP
> conditions halt you. Update `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- Modules/DataImport/app/Services/RowImporter.php`

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED (changes import semantics for the "user already exists elsewhere" case)
- **Depends on**: none
- **Category**: security (authorization / consent boundary)
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

The bulk resident importer resolves a spreadsheet row's `email` against the GLOBAL user
table (bypassing tenant scope), and if that email belongs to an existing account — even
one belonging to a DIFFERENT cluster — it attaches that user to the importing admin's
tenant with no acceptance step, and creates a `unit_user` link. A tenant admin (who holds
`create.data_import`) can therefore upload a spreadsheet naming any real platform account
and unilaterally enroll it into their own cluster, bypassing the invitation/consent flow
that exists precisely for this. The victim then appears as a resident of the attacker's
cluster. (This code predates the current audit baseline — it is a pre-existing issue, not
a regression, but it is a real cross-tenant authorization gap.)

## Current state

`backend/Modules/DataImport/app/Services/RowImporter.php`, resident-resolution block
(≈420–529):

```php
$email = ! empty($row['email']) ? mb_strtolower(trim((string) $row['email'])) : null;
$user = null;

if ($email !== null) {
    // Find by email globally (User is central, no tenant_id column)
    $user = User::withoutGlobalScope(UserTenantScope::class)
        ->where('email', $email)
        ->first();
}

// ... (fallback: match by unit + name via unit_user) ...

if ($user === null) {
    // Create the user (fresh account, auth_active=false) + attach + profile PII
    $user = User::create($userData);
    $user->tenants()->attach(tenant('id'));           // line ~475 — OK for a fresh account
    // ... UserProfile::firstOrCreate with rfc/curp/birthdate from the row ...
} else {
    // Ensure user is attached to this tenant
    if (! $user->tenants()->where('tenant.id', tenant('id'))->exists()) {
        $user->tenants()->attach(tenant('id'));        // lines ~505–508 — THE GAP
    }
}

// ... then creates the unit_user link (~515–528) ...
```

The `create` (fresh account) path is fine. The **gap is the `else` branch**: an EXISTING
global user who is not yet a member of this tenant gets `attach(tenant('id'))` with no
consent. The invitation engine that models consent already exists in the codebase (see
the resident-invitation feature — `UserInvitation`).

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Syntax | `cd backend && php -l Modules/DataImport/app/Services/RowImporter.php` | clean |
| Lint | `cd backend && vendor/bin/pint --dirty` | exit 0 |
| Test | `cd backend && php artisan test --filter=RowImporter` and `--filter=Import` | all pass incl. new case |

> Host php, shared `testing` DB, ONE process at a time. NOTE: DataImport's tests live in
> `Modules/DataImport/tests` and may not run under `phpunit.xml` until plan 063 — use the
> `--filter` name; confirm the filter actually selects tests (if 0 tests run, the suite
> isn't wired — see STOP conditions).

## Scope

**In scope**:
- `RowImporter.php` — the existing-user `else` branch (≈504–509) and its interaction with
  the `unit_user` link.
- The import validation/preview so the admin SEES what will happen (a row matching an
  out-of-tenant account should surface as a warning/blocked, not silently attach).
- Tests in `Modules/DataImport/tests`.

**Out of scope**:
- The fresh-account create path (≈452–503) — correct as-is.
- The invitation engine internals — reuse it, don't rebuild it.
- The chunked-job architecture — unchanged.

## Steps

### Step 1: Decide the policy for an existing out-of-tenant user

Two acceptable resolutions (pick one, record it in the PR):
- **(A) Consent-gated**: when an email resolves to an existing user who is NOT already a
  member of the current tenant, DO NOT attach. Instead route through the invitation flow
  (create a `UserInvitation` for that email/tenant) and mark the row as "invitación
  enviada" in the import result. The `unit_user` link is created only once the invite is
  accepted (or deferred to acceptance).
- **(B) Restrict-and-create**: only attach users who are ALREADY members of the tenant;
  for any email that resolves to a non-member existing account, treat it like a name
  collision and either create a distinct tenant-local account or block the row with a
  clear error. (Simpler, but a resident who genuinely has one cross-cluster account gets a
  duplicate — flag this tradeoff.)

Prefer (A) — it reuses the consent flow the product already has.

**Verify**: decision recorded; the `else` branch no longer calls `attach(tenant('id'))`
unconditionally.

### Step 2: Implement the chosen policy

For (A): replace the `else` attach with an invitation dispatch (reuse `UserInvitation` /
the resident-invitation service). Ensure the row's `unit_user` creation is gated so it
doesn't pre-link a non-consented user (or links only on acceptance, matching the
invitation flow's existing behavior). Surface the outcome in the per-row issues array
(the importer already builds `$issues[]` warnings).

**Verify**: `php -l` clean.

### Step 3: Tests

- **Cross-tenant enrollment blocked**: seed user U as a member of tenant B only; import a
  row in tenant A with U's email → U is NOT attached to A (assert `U->tenants()` still
  excludes A); an invitation is created (policy A) OR the row is flagged/blocked (policy B).
- **Same-tenant re-import idempotent**: a user already in tenant A re-imported → no
  duplicate membership, no error.
- **Fresh account unchanged**: a brand-new email still creates + attaches + profiles as
  before.

**Verify**: `php artisan test --filter=RowImporter` → all pass; the cross-tenant test fails against pre-fix code.

### Step 4: Format

**Verify**: `vendor/bin/pint --dirty` → exit 0.

## Done criteria

- [ ] An existing out-of-tenant account is no longer silently attached by import
- [ ] Fresh-account and same-tenant paths unchanged (regression tests green)
- [ ] Cross-tenant test fails against pre-fix code
- [ ] `php -l` clean, `vendor/bin/pint --dirty` exit 0
- [ ] `plans/README.md` row updated

## STOP conditions

- The resolution block differs from the excerpt (drift).
- `--filter=RowImporter` runs 0 tests (module suite not wired) — you can still add the
  test file, but note it won't run in CI until plan 063; report this.
- Policy (A) requires invitation-flow changes beyond a simple dispatch (e.g. the invite
  engine can't target a pre-named unit) — report and consider policy (B) instead.

## Maintenance notes

- This is the same class of gap as any "resolve user globally then attach" path — grep
  for `withoutGlobalScope(UserTenantScope` + `tenants()->attach` elsewhere during review.
- Reviewer: confirm the `unit_user` link is not created for a non-consented user.
- If bulk import for board-managed clusters ever needs to skip consent (admin explicitly
  owns the roster), that should be an explicit, audited, super-admin-only mode — not the
  default.
