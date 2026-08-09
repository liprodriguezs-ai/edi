---
description: Development commands, code quality tools, and performance guidelines
globs: "**/*.ts,**/*.tsx"
---

# Development Workflow & Best Practices

> Este workflow (React/Vite/Vitest/pnpm) aplica a la app React activa en `frontend/`. Estado canónico del stack: stack.md.

## Comandos

| Comando | Uso |
|---------|-----|
| `pnpm dev` | Servidor de desarrollo Vite |
| `pnpm build` | `tsc -b && vite build` |
| `pnpm lint` | ESLint |
| `pnpm preview` | Vista previa del build |

## Calidad de código

- **Pre-commit**: Husky + lint-staged (Prettier en archivos staged) + Commitlint (conventional commits).
- **ESLint**: imports con `@/`; max-lines 500; complexity 15; no-console (sí warn/error); no-shadow; single quotes.
- **TypeScript**: strict; verbatimModuleSyntax; no unused locals/params.

## Performance

- Filas de tabla: `memo` + `checkMemoListProps`.
- Handlers pasados a hijos: `useCallback` con dependencias correctas.
- Store: preferir selectores (`useStore(store, s => s.isLoading)`) en lugar de desestructurar todo el store cuando solo se necesitan pocos campos.
- `useMemo` solo para derivaciones costosas.

## Tamaño de archivos

- Límite recomendado 500 líneas (ESLint warn). Si un archivo crece, extraer subcomponentes o hooks.

## Tests

- Vitest + Testing Library; tests junto al código o en carpeta `__tests__` según convención del equipo.
- Prioridad: schemas Zod, hooks (useBaseList, useBaseCrud), flujos críticos (login, CRUD).
