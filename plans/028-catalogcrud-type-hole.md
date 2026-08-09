# Plan 028: Quitar el doble `as never` del resolver en `CatalogCrudModule`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat e5296c9..HEAD -- src/shared/components/catalog/CatalogCrudModule.tsx`
> Ante mismatch con el excerpt, STOP.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED (es un componente compartido por ~15 páginas de catálogo; el
  cambio es solo de tipos, pero un error de constraint rompe el build de todas)
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: frontend submodule `e5296c9`, 2026-06-12

## Why this matters

El módulo genérico de catálogos castea el schema y el resolver con `as never` —
el escape hatch más fuerte de TS. El compilador no puede verificar que el Zod
schema de cada catálogo coincida con el tipo de formulario `V`; un mismatch
falla en runtime con un error de validación ininteligible en vez de fallar el
build. La regla del proyecto es "NEVER use any" — `as never` es lo mismo con
otro nombre.

## Current state

`src/shared/components/catalog/CatalogCrudModule.tsx` líneas 134–143:

```tsx
const {
  register,
  handleSubmit,
  reset,
  control,
  formState: { errors },
} = useForm<V>({
  resolver: zodResolver(schema as never) as never,
  defaultValues: defaultValues as DefaultValues<V>,
})
```

El generic viene de `CatalogFormProps<T, V>` con
`T extends IEntity & { name?: string }, V extends FieldValues`, y `config`
trae `schema` (tipo declarado más arriba en el archivo — leerlo entero antes de
empezar). Stack: zod 4.3.x + react-hook-form 7.71.x + `@hookform/resolvers`
(ver versión exacta en `frontend/package.json`).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Typecheck/build | `cd frontend && pnpm build` | exit 0 |
| Tests | `cd frontend && pnpm test` | all pass |
| Docs de tipos | Context7 / docs de `@hookform/resolvers` v5 + zod v4 | — |

## Suggested executor toolkit

- Leer primero cómo otros formularios del repo SIN el hack tipan
  `zodResolver`: `grep -rn "zodResolver(" frontend/src/modules | grep -v "as never" | head -5`
  y abrir 2. Esa es la combinación de tipos que el proyecto ya sabe que
  compila — replicarla a nivel genérico.

## Scope

**In scope**:
- `src/shared/components/catalog/CatalogCrudModule.tsx` (tipos del config y del form)
- Las páginas de catálogo SOLO si el cambio de constraint exige ajustar cómo
  declaran su `schema` en el config (idealmente cero cambios).

**Out of scope**:
- Lógica de CRUD, render, `useBaseCrud`, `useCatalogs` — solo tipos.
- Cambiar versiones de zod/RHF/resolvers.

## Git workflow

- Commit dentro del submódulo `frontend/`. Ej:
  `types(catalog): remove as-never resolver casts in CatalogCrudModule`.

## Steps

### Step 1: Entender el constraint real

Abrir el archivo entero y anotar cómo está tipado `schema` en el config
(`z.ZodType<V>`? `z.ZodSchema`? otro). Abrir los 2 exemplares sin hack del
grep de arriba y anotar qué tipo de schema reciben.

### Step 2: Alinear el genérico

Objetivo: que `useForm<V>({ resolver: zodResolver(schema), ... })` compile SIN
casts. Rutas en orden de preferencia:

1. Derivar `V` del schema en vez de declararlos independientes: el config se
   tipa con `schema: S extends z.ZodType` y `V = z.infer<S>` (o
   `z.output<S>`), de modo que resolver y form comparten origen.
2. Si la inferencia con zod 4 + resolvers exige input/output split, tipar
   `schema: z.ZodType<V, any>` (zod 4 tiene 2-3 params de genérico — verificar
   con la versión instalada).
3. Si tras 1–2 sigue sin alinear, fallback aceptable: UN solo cast localizado
   `resolver: zodResolver(schema) as Resolver<V>` (importando `Resolver` de
   react-hook-form) con un comentario de una línea del porqué. PROHIBIDO
   `as never`/`as any`.

**Verify**: `pnpm build` → exit 0 (esto compila las ~15 páginas de catálogo).

### Step 3: Confirmar que la validación sigue viva

Smoke en un catálogo (p.ej. Tipos de Unidad): enviar el form vacío → aparecen
los errores de Zod; crear y editar un registro → funciona.

**Verify**: `pnpm test` → pass.

## Test plan

- Si plan 029 ya existe, sus tests no se ven afectados. Opcional (bonus): un
  test de tipos con `// @ts-expect-error` demostrando que un schema que no
  matchea `V` ya no compila — solo si sale natural, no es requisito.

## Done criteria

- [ ] `grep -n "as never" frontend/src/shared/components/catalog/CatalogCrudModule.tsx` → 0 matches
- [ ] `pnpm build` exit 0
- [ ] `pnpm test` pass
- [ ] `plans/README.md` row updated

## STOP conditions

- La ruta 1 y 2 fallan Y el fallback 3 también requiere tocar >3 páginas de
  catálogo: la incompatibilidad es estructural (zod4/resolvers); reportar el
  error de tipos exacto en vez de forzar.
- Cualquier cambio de COMPORTAMIENTO observable en los forms de catálogo.

## Maintenance notes

- Al crear catálogos nuevos, el tipo del schema en el config ya queda
  verificado por el compilador — si un catálogo nuevo "necesita" un cast, el
  schema está mal, no el config.
