---
name: lint-baseline-2026-06
description: ESLint baseline as of June 2026 audit — 327 problems (304 errors, 23 warnings)
metadata:
  type: project
---

ESLint baseline measured on 2026-06-12:

- **Total**: 327 problems (304 errors, 23 warnings)
- **208 errors and 2 warnings** auto-fixable with --fix

## Error breakdown by category
- `no-unused-vars`: ~42 violations (Entity.ts ERoleUserSlug enum members, filter args, etc.)
- `complexity > 15`: ~35 violations — worst offenders:
  - `src/modules/collaborator/helpers/collaboratorImport.ts:41` — complexity 51
  - `src/modules/collaborator/components/CollaboratorProfileHeader.tsx` — 21
  - `src/shared/hooks/useBaseList.ts` — two functions at 35 and 34 (core hook, not easily split)
  - `ChargeBatchFormModal.tsx` — 21
  - `SubscriptionPlanList.tsx (PlanCard)` — 22
  - `CommunityProjectListPage.tsx` — 33
  - `ExpenseFormModal.tsx` — 28
- `no-console`: ~11 violations in `src/shared/firebase/useFirebaseMessaging.ts`
- `max-lines (>500)`: 3 warnings
  - `ChargeBatchFormModal.tsx` — 580 lines (critical)
  - Two others at ~552 and ~522

## Previously known (do NOT re-report)
- Bundle code-splitting/lazy Firebase — DONE
- Refresh-token race — DONE
- Visitor registration form — DONE
- Voluntary contribution UI — DONE
- Dead deps xlsx/recharts — DONE

**Why:** Measuring this baseline matters because the user wants these fixed in the next cycle.
