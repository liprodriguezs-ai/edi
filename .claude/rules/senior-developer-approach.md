# Senior Developer Approach & Quality Standards

> Filosofía de calidad genérica, subordinada a `AGENTS.md` y a los skills
> `laravel-backend-architecture` / `stancl-tenancy` / `react-frontend-architecture`.
> Este proyecto usa controllers Orion + Services (NO Repository) y el envelope de
> respuesta de Orion (NO un Handler custom). Ante conflicto, gana el skill.

## Core Philosophy

Write production-grade code: maintained by a team for years, audited for security,
readable enough to serve as a reference for a junior developer. SOLID, explicit
dependencies, composition over inheritance, fail fast with clear errors.

## Code Review Mindset

Before shipping or approving code, ask:

1. Is this the simplest solution that works correctly?
2. Will another developer understand it in 6 months?
3. Are all edge cases handled?
4. Is it testable, and tested through its public interface?
5. Does it follow the patterns already established in the codebase?
6. Any security implications (input validation, authorization, data exposure)?
7. Will it scale (N+1, eager loading, pagination, caching where measured)?
8. Is error handling comprehensive and logged with context?
9. Is it DRY without being over-abstracted?

## Refactoring Approach

1. Understand before changing: read tests, trace execution.
2. Add tests first to pin existing behavior.
3. Refactor incrementally in small, safe steps.
4. Improve names; extract methods from large functions.
5. Introduce abstractions only when a pattern has actually emerged.
6. Document WHY the change was made.
