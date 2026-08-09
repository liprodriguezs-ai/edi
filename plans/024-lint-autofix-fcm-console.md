# Plan 024: Lint fase 1 — autofix masivo + limpiar `console.log` del flujo FCM

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C frontend status --short` y `git -C frontend diff --stat e5296c9..HEAD`.
> OJO: el working tree del frontend tiene cambios SIN COMMITEAR que son parte
> del estado auditado. Antes de ejecutar este plan, el operador debe commitear
> ese trabajo en curso (es de plan 018 categoría C). NO ejecutar `--fix` sobre
> un working tree sucio: STOP y pedir el commit previo.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW (autofixes mecánicos + borrado de logs informativos)
- **Depends on**: none (pero exige working tree limpio — ver drift check)
- **Category**: dx
- **Planned at**: frontend submodule `e5296c9`, 2026-06-12

## Why this matters

El lint del frontend está en **327 problemas (304 errores, 23 warnings)**, de
los cuales **208 son auto-corregibles** (medido el 2026-06-12 con `pnpm lint`).
Con ese ruido, el pre-commit no distingue regresiones nuevas de backlog viejo.
Además `useFirebaseMessaging.ts` tiene 11 `console.log` en el camino de
registro de push que imprimen en producción — incluyendo el **token FCM
completo** (línea 69) y rutas internas del backend.

## Current state

- `frontend/src/shared/firebase/useFirebaseMessaging.ts` — hook de registro FCM.
  11 `console.log` (líneas 32, 39, 42, 45, 48, 51, 56, 69, 72, 76, 78), un
  `console.warn` legítimo (línea 66: "push service error" sin HTTPS) y un
  `console.error` legítimo (línea 82). El peor:

```ts
console.log('[FCM] Token obtenido:', token)        // línea 69 — token completo
// ...
console.log('[FCM] Enviando token al backend...')  // línea 76
await apiClient.patch('/user/setTokenSender', { token_sender: token })
console.log('[FCM] Token guardado en backend OK')  // línea 78
```

- Regla ESLint del proyecto: `no-console` permite SOLO `warn`/`error`
  (`.claude/rules/development-workflow.md`).
- Baseline de lint completo: 327 problemas. Los NO auto-corregibles que
  permanecerán tras esta fase son mayormente `complexity` y `max-lines`
  (cubiertos por planes 030, 031, 032 y 033 — NO intentarlos acá).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Lint | `cd frontend && pnpm lint` | ver conteos abajo |
| Autofix | `cd frontend && pnpm lint --fix` | exit ≠ 0 (quedan errores no-fixeables: esperado) |
| Tests | `cd frontend && pnpm test` | all pass |
| Build/typecheck | `cd frontend && pnpm build` | exit 0 |

## Scope

**In scope**:
- Todo cambio producido automáticamente por `pnpm lint --fix`
- `frontend/src/shared/firebase/useFirebaseMessaging.ts` (edición manual de consoles)
- Cualquier `no-console` restante que el lint reporte (solo cambiar/borrar la
  línea de console, nada más)

**Out of scope**:
- TODO error `complexity` y warning `max-lines` (planes 030–033).
- Cambios de lógica de cualquier tipo. Si un autofix cambia semántica
  (no debería), revertirlo y anotarlo.

## Git workflow

- Commits dentro del submódulo `frontend/`. DOS commits separados para review
  limpio: (1) `style: apply eslint --fix across src` (solo autofixes),
  (2) `fix(fcm): remove debug console.log from messaging flow`.

## Steps

### Step 1: Baseline

`pnpm lint 2>&1 | tail -3` → anotar el conteo exacto (esperado ~327 si no hubo
drift).

### Step 2: Autofix

`pnpm lint --fix`. Revisar el diff completo (`git -C frontend diff --stat`):
deben ser solo reordenes de imports, `prefer-const`, espaciado, etc. Después:
`pnpm test` → all pass; `pnpm build` → exit 0.

**Verify**: `pnpm lint 2>&1 | tail -3` → problemas ≤ 120 (327 − 208 ≈ 119).

### Step 3: Limpiar el flujo FCM

En `useFirebaseMessaging.ts`: eliminar los 11 `console.log`. Conservar el
`console.warn` de la línea 66 y el `console.error` de la 82. NUNCA loguear el
token (ni parcial). Si se quiere traza de debug local, envolver en
`if (import.meta.env.DEV)` — opcional, no requerido.

**Verify**: `grep -c "console.log" src/shared/firebase/useFirebaseMessaging.ts` → 0.

### Step 4: Barrer `no-console` restantes

`pnpm lint 2>&1 | grep no-console` → para cada hit: si es información de debug,
borrar; si es manejo de error, pasar a `console.error`.

**Verify**: `pnpm lint 2>&1 | grep -c no-console` → 0.

## Test plan

Sin tests nuevos. Gate: `pnpm test` y `pnpm build` verdes tras cada step (este
plan no debe cambiar comportamiento observable).

## Done criteria

- [ ] `pnpm lint` → 0 errores auto-fixeables, 0 `no-console`; restantes son solo `complexity`/`max-lines`
- [ ] `grep -c "console.log" frontend/src/shared/firebase/useFirebaseMessaging.ts` → 0
- [ ] `pnpm test` → all pass; `pnpm build` → exit 0
- [ ] Dos commits separados (autofix / fcm)
- [ ] `plans/README.md` row updated

## STOP conditions

- El working tree del frontend tiene cambios sin commitear al arrancar.
- `pnpm lint --fix` produce un diff con cambios de lógica (no solo estilo).
- `pnpm test` o `pnpm build` rompen tras el autofix y la causa no es evidente
  en un intento.

## Maintenance notes

- Tras este plan, el lint queda con SOLO deuda de complejidad/tamaño — los
  planes 030–033 la bajan a cero. A partir de ahí el pre-commit vuelve a ser
  señal confiable; cualquier PR que sume un error de lint nuevo debe rechazarse.
