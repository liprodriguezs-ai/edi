# Plan 003: Move `env()` calls out of application code into config

> **Executor instructions**: Follow step by step; run every verification and
> confirm the expected result before moving on. On any "STOP condition", stop
> and report. When done, update this plan's row in `plans/README.md`.
>
> **Drift check (run first)**: from the repo root,
> `cd backend && git diff --stat 803df3a..HEAD -- core/src/helpers.php core/src/Models/Subscription.php config/`
> Mismatch with "Current state" excerpts = STOP.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: backend commit `803df3a`, 2026-06-11

## Why this matters

Laravel returns `null` from `env()` once `php artisan config:cache` has run —
the standard production optimization. Two pieces of application code read
secrets/config through `env()` directly instead of `config()`: the Stripe API
key in a core helper, and a default-plan value in a model. In a config-cached
production deploy these resolve to `null`, so **Stripe API calls silently
authenticate with an empty key** (subscriptions break with confusing errors).
The repo's own convention (`CLAUDE.md`: "never use the `env()` function directly
outside of config files") is being violated here. This plan routes both through
`config()`. **Do not put any secret value in any file you write** — config
files read from `env()`; the secret stays in `.env` only.

## Current state

- `backend/core/src/helpers.php` (~line 67) — inside `getConfigStripe()`:
  ```php
  'api_key' => env('STRIPE_SECRET'),
  ```
- `backend/core/src/Models/Subscription.php` (~lines 21, 25) — `env('PLAN_DEFAULT')` called from a model method.
- `backend/config/` — Laravel config directory. Check whether `config/services.php` exists (standard Laravel location for third-party credentials like Stripe) and whether a Stripe entry already exists there.

**Convention**: config files are the ONLY place `env()` is allowed. A typical
Laravel `config/services.php` Stripe entry looks like:
```php
'stripe' => [
    'secret' => env('STRIPE_SECRET'),
    'key' => env('STRIPE_KEY'),
],
```

## Commands you will need

| Purpose      | Command (from `backend/`)            | Expected             |
|--------------|--------------------------------------|----------------------|
| Lint         | `php -l <file>`                       | `No syntax errors`   |
| Config check | `php artisan config:clear`           | exit 0               |
| Tinker read  | `php artisan tinker --execute="echo config('services.stripe.secret') ? 'set' : 'null';"` | prints `set` if `.env` has the key |
| Tests        | `php artisan test tests/Feature/Configuration` | all pass     |

## Scope

**In scope**:
- `backend/core/src/helpers.php`
- `backend/core/src/Models/Subscription.php`
- `backend/config/services.php` (edit or create the Stripe entry)
- `backend/config/app.php` OR a suitable config file for the default-plan value

**Out of scope**:
- `.env` / `.env.example` content changes beyond confirming the keys exist (the
  values must NOT be written into any committed file).
- Any change to Cashier/Stripe call sites other than the key resolution.

## Git workflow

- Branch: `advisor/003-env-to-config`
- Conventional commit: `fix(config): resolve Stripe secret and default plan via config(), not env()`
- No push/PR unless instructed. `backend/` is a submodule.

## Steps

### Step 1: Add a Stripe config entry

If `config/services.php` has no `stripe` block, add one:
```php
'stripe' => [
    'secret' => env('STRIPE_SECRET'),
],
```
If a block already exists, ensure it has a `secret` key reading `env('STRIPE_SECRET')`.

### Step 2: Point the helper at config

In `core/src/helpers.php`, replace `env('STRIPE_SECRET')` with
`config('services.stripe.secret')`.

### Step 3: Add a default-plan config entry and use it

Choose a config home for the default plan — `config/app.php` with a new key
`'plan_default' => env('PLAN_DEFAULT')`, or a dedicated `config/subscription.php`.
Then in `core/src/Models/Subscription.php` replace both `env('PLAN_DEFAULT')`
calls with `config('app.plan_default')` (matching wherever you put it).

**Verify** (after steps 1–3):
- `php -l core/src/helpers.php` → `No syntax errors`
- `php -l core/src/Models/Subscription.php` → `No syntax errors`
- `grep -rn "env('STRIPE_SECRET')\|env('PLAN_DEFAULT')" core/` → only matches are inside `config/` (there should be **zero** matches under `core/`).
- `php artisan config:clear` → exit 0.

### Step 4: Confirm resolution end-to-end

Run the tinker read command from the table. If `.env` has `STRIPE_SECRET` set it
prints `set`; if `.env` lacks it, it prints `null` — that's fine for this plan
(the key may be unset in dev). The goal is that the value now flows through
`config()`.

## Test plan

No new behavioral test is strictly required (this is a config-plumbing fix), but
add a lightweight guard so the regression can't silently return:

- In an existing config-related test under `tests/Feature/Configuration/` (or a
  new `tests/Feature/Core/StripeConfigTest.php` modeled on the simplest existing
  feature test), assert that `config('services.stripe.secret')` resolves to the
  same value as `env('STRIPE_SECRET')` in the test environment (both may be null —
  assert equality, not truthiness).

**Verify**: `php artisan test --filter StripeConfig` (or the file you used) → passes.

## Done criteria

ALL must hold (from `backend/`):

- [ ] `grep -rn "env('STRIPE_SECRET')" core/ Modules/ app/` returns matches ONLY inside `config/` (ideally zero under those three dirs).
- [ ] `grep -rn "env('PLAN_DEFAULT')" core/ Modules/ app/` likewise.
- [ ] `php artisan config:clear` exits 0.
- [ ] `php artisan test tests/Feature/Configuration` exits 0.
- [ ] No secret value appears in any committed file (`git diff` shows only `env(...)` references in config, never literal keys).
- [ ] No out-of-scope files modified.
- [ ] `plans/README.md` status row updated.

## STOP conditions

- "Current state" excerpts don't match (drift).
- A Stripe entry already exists in `config/services.php` but reads a DIFFERENT
  env key name than `STRIPE_SECRET` — report which name is canonical before
  changing call sites.
- The `Subscription` model's `env('PLAN_DEFAULT')` is used in a static context
  that runs before config is bootstrapped (rare) — report it.

## Maintenance notes

- New third-party credentials should go in `config/services.php` and be read via
  `config()`. Grep `env(` across `core/`, `Modules/`, `app/` periodically — only
  `config/` should match.
- A reviewer must verify NO secret literal entered git, and that
  `php artisan config:cache` (the production path) would now yield the real key.
