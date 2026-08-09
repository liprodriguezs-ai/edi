# Plan 065: Add Spanish labels for `trial` / `legacy_plan` entitlement sources (Spanish-UI rule)

> **Executor instructions**: Follow step by step; verify each step. Update
> `plans/README.md` when done.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat 8f3675b..HEAD -- src/modules/tenant/components/billing/EntitlementsSection.tsx`
> and `git -C backend diff --stat 8117e66..HEAD -- Modules/SubscriptionPlan/app/Models/TenantEntitlement.php`

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug (i18n / convention)
- **Planned at**: frontend `8f3675b`, backend `8117e66`, 2026-07-01

## Why this matters

The super-admin billing panel maps entitlement `source` values to Spanish labels, but
the map is missing two real backend sources — `trial` and `legacy_plan` — and includes
a dead key (`paid`) no backend source emits. The billing-summary endpoint returns ALL
entitlements unfiltered by source, so a cluster with an active or past per-module trial
renders the literal English word "trial" (or "legacy_plan" for older tenants) straight
into the UI via the fallback. That violates the hard "UI always in Spanish" project
rule — and it's exactly the feature this recent batch shipped (per-module "Activar
prueba" trial CTAs), so the new source value was never wired into the label map.

## Current state

- `frontend/src/modules/tenant/components/billing/EntitlementsSection.tsx` (lines 30–35):
  ```ts
  const SOURCE_LABELS: Record<string, string> = {
    super_admin_free: 'Cortesía gratuita',
    paid: 'Pagado',
    stripe: 'Pagado (Stripe)',
    manual: 'Pagado (manual)',
  }
  ```
  Rendered (≈ line 212) as `SOURCE_LABELS[entitlement.source] ?? entitlement.source` —
  so an unmapped source falls back to the raw English string.
- Backend truth — `backend/Modules/SubscriptionPlan/app/Models/TenantEntitlement.php`
  (≈ lines 33–55) defines `SOURCES = [stripe, super_admin_free, manual, trial, legacy_plan]`.
  `trial` and `legacy_plan` are missing from the map; `paid` is not a real source.
- `backend/Modules/SubscriptionPlan/app/Http/Controllers/TenantBillingController.php`
  (`loadEntitlements`, ≈71–81) returns all entitlements (active AND expired), so
  trial-sourced rows are genuinely reachable in this panel.

Convention (repo CLAUDE.md strict rule 2 + Spanish dialect memory): UI text in NEUTRAL
Mexican Spanish. Use "Prueba gratuita" for trial and "Plan legado" for legacy_plan.

## Commands you will need

| Purpose | Command | Expected |
|---------|---------|----------|
| Typecheck | `cd frontend && pnpm exec tsc --noEmit` | exit 0 (or unchanged vs. baseline) |
| Lint (file) | `cd frontend && pnpm exec eslint src/modules/tenant/components/billing/EntitlementsSection.tsx` | 0 new problems |
| Build (optional) | `cd frontend && pnpm build` | exit 0 |

## Scope

**In scope**:
- `frontend/src/modules/tenant/components/billing/EntitlementsSection.tsx` — the
  `SOURCE_LABELS` object only.

**Out of scope**:
- The backend source constants (they are correct).
- Filtering which entitlements the endpoint returns (returning expired ones is by design).

## Steps

### Step 1: Complete the label map

```ts
const SOURCE_LABELS: Record<string, string> = {
  super_admin_free: 'Cortesía gratuita',
  stripe: 'Pagado (Stripe)',
  manual: 'Pagado (manual)',
  trial: 'Prueba gratuita',
  legacy_plan: 'Plan legado',
}
```

Remove the dead `paid` key (no backend source emits it — confirm with
`git -C backend grep -n "'paid'" Modules/SubscriptionPlan/app/Models/TenantEntitlement.php`
returning nothing). If you prefer safety, keep `paid` — but the two additions are the
required fix; the removal is cleanup.

**Verify**: `cd frontend && pnpm exec tsc --noEmit` → no new errors.

### Step 2: Confirm no other consumer of the source strings needs the same labels

`grep -rn "SOURCE_LABELS\|entitlement.source" frontend/src` — if the source is rendered
raw anywhere else, apply the same map (import/extract if needed). If it's only here,
done.

**Verify**: `pnpm exec eslint <file>` → 0 new problems.

## Done criteria

- [ ] `trial` and `legacy_plan` render Spanish labels; no raw English source reaches the UI
- [ ] `pnpm exec tsc --noEmit` exit 0 (no new errors)
- [ ] `pnpm exec eslint <file>` 0 new problems
- [ ] Only `EntitlementsSection.tsx` modified
- [ ] `plans/README.md` row updated

## STOP conditions

- `SOURCE_LABELS` differs from the excerpt (drift).
- The backend `SOURCES` list has additional values beyond the five listed (re-read the
  model and map ALL of them).

## Maintenance notes

- Any new entitlement source added to the backend `SOURCES` constant MUST get a Spanish
  label here — the fallback silently ships English otherwise.
- Reviewer: this is the general pattern of the bug — a backend enum grows and a frontend
  label map doesn't; worth a quick grep for other `Record<string,string>` label maps
  keyed on backend enums during review.
