---
name: project-conventions
description: Stack and convention rules specific to this React 19 + Vite + Tailwind v4 + shadcn project
metadata:
  type: project
---

## Toast
- Use `{ toast }` from `@/shared/hooks/useToast` (sileo). Call `toast.success(title, msg)`, `toast.error(title, msg)`, `toast.info(title, msg)`.
- NEVER `useToast()` or `toast({ title, variant })` (shadcn pattern — banned).

## Date formatting
- Always `displayFormatDate()` from `@/shared/utils/dateHelper`. Never `new Date(...)`.

## HTTP client
- Always `apiClient` from `@/shared/api/apiClient`. Never raw `axios`.
- `createCrudApi()` for standard CRUD resources.

## TypeScript
- No `any`. Entity interfaces extend `IEntity` from `@/shared/interfaces/Entity`.
- Inline object types for simple relations (`type?: { id: string; name: string }`), no cross-module type imports for simple shapes.

## File size limit
- ESLint warns at 500 lines. SelectPlanPage is 421 lines — close to limit, watch for growth.

## Async event handlers
- React 19 does not warn about async event handlers, but void-wrapping is still conventional for non-promise-returning handlers.
