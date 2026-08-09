# Plan 026: Quick fixes UI — fecha con `displayFormatDate` en suscripción y memo/handlers estables en Chat

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat e5296c9..HEAD -- src/modules/user/SubscriptionPage.tsx src/modules/chat/`
> Ante mismatch con los excerpts, STOP.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug + perf
- **Planned at**: frontend submodule `e5296c9`, 2026-06-12

## Why this matters

1. `SubscriptionPage` formatea fechas con `new Date().toLocaleDateString` — el
   anti-pattern §4 explícito. Con ISO UTC del server y timezone de México, las
   fechas cercanas a medianoche pueden mostrarse un día corridas en la página
   de facturación (donde un día sí importa).
2. `ChatPage` re-renderiza TODAS las burbujas cada 10 segundos: el query de
   mensajes tiene `refetchInterval: 10_000`, `ChatMessageBubble` NO está
   memoizado (verificado: `export function ChatMessageBubble(...)` plano) y los
   6 handlers se crean inline dentro del `map`.

## Current state

`src/modules/user/SubscriptionPage.tsx` líneas 55–62:

```tsx
function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('es-MX', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}
```

Helper canónico — `src/shared/utils/dateHelper.ts` línea 4 (firma REAL,
verificada):

```ts
export const displayFormatDate = (dateStr: string | null | undefined, formatD: 'medium' | 'mediumDate' = 'medium') => {
```

`src/modules/chat/ChatPage.tsx`: `refetchInterval: 10 * 1000` (línea 69);
burbujas en líneas 172–186:

```tsx
messages.map((message) => (
  <ChatMessageBubble
    key={message.id}
    ...
    pending={editMutation.isPending || reactMutation.isPending || hideMutation.isPending || unhideMutation.isPending}
    onReport={(m) => setReportTarget(m)}
    onHide={(m) => hideMutation.mutate(m)}
    onUnhide={(m) => unhideMutation.mutate(m)}
    onReact={(m, hasReacted) => reactMutation.mutate({ message: m, hasReacted })}
    onEdit={(id, body) => editMutation.mutateAsync({ id, body })}
    onDirectMessage={isDirectChannel ? undefined : (m) => directMutation.mutate(m)}
  />
))
```

`src/modules/chat/components/ChatMessageBubble.tsx` línea 123:
`export function ChatMessageBubble({ message, currentUserId, canModerate, pending, onReport, onHide, onUnhide, onReact, onEdit, onDirectMessage }: ChatMessageBubbleProps)`.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Build | `cd frontend && pnpm build` | exit 0 |
| Lint | `cd frontend && pnpm lint` | sin errores nuevos |
| Tests | `cd frontend && pnpm test` | all pass |

## Scope

**In scope**:
- `src/modules/user/SubscriptionPage.tsx`
- `src/modules/chat/ChatPage.tsx`
- `src/modules/chat/components/ChatMessageBubble.tsx`

**Out of scope**:
- El `refetchInterval` (10s es decisión de producto; no tocarlo).
- `CurrentPlanSection` complejidad 20 (lo cubre plan 033).
- Cualquier otro `new Date(...)` que sea ARITMÉTICA de fechas y no formateo
  para mostrar (p.ej. `getTenure` en CollaboratorProfileHeader): no es el
  anti-pattern.

## Git workflow

- Commits dentro del submódulo `frontend/`:
  `fix(subscription): use displayFormatDate for billing dates` y
  `perf(chat): memoize message bubble and stabilize handlers`.

## Steps

### Step 1: SubscriptionPage

Borrar el helper local `formatDate` e importar
`displayFormatDate` de `@/shared/utils/dateHelper`; en cada call-site usar
`displayFormatDate(iso, 'mediumDate')`. OJO: la firma real solo acepta
`'medium' | 'mediumDate'` — no inventar formatos. El render cambia de
"12 de junio de 2026" al formato del helper: aceptado (consistencia > formato
largo).

**Verify**: `grep -n "toLocaleDateString" src/modules/user/SubscriptionPage.tsx` → 0; `pnpm build` exit 0.

### Step 2: Memoizar la burbuja

En `ChatMessageBubble.tsx`:

```tsx
import { memo } from 'react'
// ...
export const ChatMessageBubble = memo(function ChatMessageBubble({ ... }: ChatMessageBubbleProps) {
  // cuerpo idéntico
})
```

El nombre exportado no cambia → imports intactos.

**Verify**: `pnpm build` exit 0.

### Step 3: Handlers estables en ChatPage

Crear con `useCallback` (deps: la mutation correspondiente / setters):
`handleReport`, `handleHide`, `handleUnhide`, `handleReact`, `handleEdit`,
`handleDirectMessage`. Calcular `const anyActionPending = ...` UNA vez fuera
del map. Pasar las referencias estables como props. `onDirectMessage`:
mantener el ternario `isDirectChannel ? undefined : handleDirectMessage`.
Nota: las mutations de TanStack tienen `mutate` estable, así que
`useCallback(..., [])` con el objeto mutation referenciado es suficiente; no
pelear con exhaustive-deps — incluir la mutation en deps está bien.

**Verify**: `pnpm lint` sin errores nuevos en `src/modules/chat/`; `pnpm build` exit 0.

## Test plan

- Si plan 029 ya creó tests de chat (`useSendMessage.test.ts`), correrlos:
  deben seguir verdes sin cambios.
- Smoke manual: enviar mensaje, reaccionar, ocultar como moderador, abrir DM —
  todo igual que antes.

## Done criteria

- [ ] `grep -rn "toLocaleDateString" frontend/src/modules/user/SubscriptionPage.tsx` → 0
- [ ] `ChatMessageBubble` envuelto en `memo`; cero arrow functions inline en el `map` de mensajes de `ChatPage`
- [ ] `pnpm build` exit 0; `pnpm test` pass; `pnpm lint` sin errores nuevos
- [ ] `plans/README.md` row updated

## STOP conditions

- Los excerpts no coinciden (p.ej. la firma de `displayFormatDate` cambió).
- Tras memoizar, las burbujas dejan de actualizarse al editar/reaccionar
  (señal de que alguna prop necesaria quedó fuera — revisar `pending`), y el
  fix no es obvio en un intento.

## Maintenance notes

- Si se agregan acciones nuevas a la burbuja, mantener la disciplina: handler
  con `useCallback` en la página, prop estable, y revisar que `memo` siga
  siendo efectivo (React DevTools Profiler con el poll de 10s).
