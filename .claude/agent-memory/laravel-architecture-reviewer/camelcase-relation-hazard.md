---
name: camelcase-relation-hazard
description: Why camelCase Eloquent relation methods are a latent contract bug in this project, and where they live in Collections/Housing/Treasury
metadata:
  type: pattern
---

Pattern: camelCase relation methods (e.g. `chargeBatches()`, `paymentApplications()`) violate laravel-backend-architecture §2.3 / rule 4 (relation method name = include key = JSON key, snake_case, NO reliance on auto-conversion).

Why it "works" anyway and why that's the trap:
- `BaseModel` does NOT override `$snakeAttributes` → Laravel default `true` → on serialization the relation key is snake_cased. So `chargeBatches` is sent in the include string AND whitelisted as `chargeBatches`, but the JSON the frontend receives is `charge_batches`. Two opposing names held together by framework magic.
- Proof in repo: `frontend/src/modules/chargePolicy/schema.ts` reads `charge_batches?` (snake) and its own comment documents "include `chargeBatches` (clave JSON snake_case `charge_batches` por la serialización de Laravel)". The include string in `ChargeRuleFormPage.tsx` still sends `chargeBatches`.

Contract hazard (why CRITICAL): a PARTIAL rename breaks things.
- Rename method but not `includes()` whitelist → Orion 422 "include not allowed".
- Rename method but not internal `with()/whereHas()/->exists()` → BadMethodCallException.
- Rename backend include but not the frontend `include:` string → Orion 422.
Fix must be atomic across: model method + controller `includes()` + every `with()/whereHas()/->exists()/->count()` + tests + the frontend `include:` string. The frontend RESPONSE-reading code already expects snake_case, so it usually needs NO change.

Known camelCase relations as of 2026-06 audit:
- Collections: `ChargePolicy::chargeBatches()`, `Charge::paymentApplications()` (only two in the module).
- Housing (out of Collections scope, pre-existing): `Unit::unitType()`, `Unit::unitStatus()`, `Unit::unitUsers()`, `UnitUser::unitRelationship()`, `UnitRelationship::unitUsers()`.
- Treasury: `Expense::beneficiaryType()`, `Expense::authorizedBy()`.

The Housing precedent is why a prior reviewer (see `backend/.claude/agent-memory/laravel-architecture-reviewer/collections-slice-a-charge-policy.md`) rationalized `chargeBatches`. The convention is still clear: snake_case. Multi-word collection relations done RIGHT in the same module: `ChargePolicy::surcharge_rules()`, `Payment::applications()`.
