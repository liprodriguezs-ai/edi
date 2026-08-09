# Plan 027: Eliminar llamadas directas a `apiClient` en componentes (capa Resource + schema.ts)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat e5296c9..HEAD -- src/modules/notification src/modules/tenant src/modules/subscriptionPlan src/modules/unit/components/UserCombobox.tsx`
> Ante mismatch con los excerpts, STOP.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: LOW (mover código de capa, sin cambiar requests reales)
- **Depends on**: none
- **Category**: tech-debt
- **Planned at**: frontend submodule `e5296c9`, 2026-06-12

## Why this matters

El contrato de 5 capas del proyecto (schema → resource → store → hook →
componente) se rompe en 6 sitios que llaman `apiClient` directo desde
componentes, y el módulo `notification` ni siquiera tiene `schema.ts` (define
`INotificationUser` inline en la página — anti-pattern §4 explícito). Cada
sitio directo queda fuera de cualquier cambio futuro de la capa API y duplica
shapes de tipos.

## Current state

Sitios verificados por grep (2026-06-12):

1. `src/modules/notification/NotificationListPage.tsx` — 3 llamadas + interface inline (líneas 11–19, 29, 41, 49):

```tsx
interface INotificationUser extends IEntity {
  is_read: boolean
  notification?: { id: string; title?: string; message?: string; click_action?: string | null }
}
// queryFn:
const { data } = await apiClient.post<{ data: INotificationUser[] }>(
  '/notification_user/search?limit=50',
  { includes: [{ relation: 'notification' }], sort: [{ field: 'created_at', direction: 'desc' }] },
)
// mutations:
mutationFn: () => apiClient.post('/notification_user/mark-all-read'),
mutationFn: (id: string) => apiClient.patch(`/notification_user/${id}`, { is_read: true }),
```

2. `src/modules/tenant/components/TenantSystemsModal.tsx:37` — `apiClient.get<{ data: ISystem[] }>('/system', {...})`
3. `src/modules/subscriptionPlan/components/PlanSystemsModal.tsx:33` — ídem (mismo endpoint `/system`)
4. `src/modules/unit/components/UserCombobox.tsx:9–15`:

```tsx
async function searchUsers(term: string): Promise<IUser[]> {
  // UserController no expone sortableBy — cualquier sort es 422 en Orion
  const { data } = await apiClient.post<{ data: IUser[] }>('/user/search?limit=8', {
    search: { value: term },
  })
  return data.data
}
```

Convención: los Resources viven en `src/modules/{m}/services/` y usan
`createCrudApi<T, V>({ basePath })` (de `@/shared/api/...`) más métodos custom.
ANTES de escribir, leer un exemplar real:
`ls src/modules/payment/services src/modules/chat/services` y abrir uno que
combine `createCrudApi` con endpoints custom (los Resources de chat usados en
`ChatPage` — `ChatMessageResource.hide/react/edit` — son buen modelo).

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Grep gate | ver Done criteria | 0 matches |
| Build | `cd frontend && pnpm build` | exit 0 |
| Lint/Tests | `pnpm lint` / `pnpm test` | sin errores nuevos / pass |

## Scope

**In scope**:
- `src/modules/notification/schema.ts` (create), `src/modules/notification/services/NotificationResource.ts` (create), `NotificationListPage.tsx` (consumir)
- `src/modules/system/services/SystemResource.ts` (create o extender si ya existe — verificar `ls src/modules/system/services`)
- `TenantSystemsModal.tsx`, `PlanSystemsModal.tsx` (consumir SystemResource)
- `src/modules/user/services/` (agregar búsqueda con límite si no existe), `UserCombobox.tsx` (consumir)

**Out of scope**:
- `src/shared/api/apiClient.ts` y `createCrudApi` — no tocar.
- Cambiar endpoints, params o shapes de request (los requests al backend deben
  quedar BYTE-IGUALES; esto es un movimiento de capa).
- Otros usos de `apiClient` dentro de `services/` o `shared/` (ahí es legítimo).

## Git workflow

- Commit dentro del submódulo `frontend/`. Ej:
  `refactor(api-layer): move direct apiClient calls into module resources`.

## Steps

### Step 1: Módulo notification completo

Crear `schema.ts` con `INotificationUser` (mover la interface tal cual).
Crear `NotificationResource.ts`: método `search` (POST
`/notification_user/search?limit=50` con los mismos includes/sort por defecto o
parametrizados), `markAllRead()` (POST `/notification_user/mark-all-read`),
`markRead(id)` (PATCH `/notification_user/{id}` con `{ is_read: true }`).
Actualizar la página para importar tipo y resource. Si `createCrudApi` no
modela bien el search de Orion con `?limit=` en query, usar el patrón que use el
exemplar de chat (resource hecho a mano sobre apiClient DENTRO de services/ es
aceptable; lo prohibido es apiClient en componentes).

**Verify**: `pnpm build` exit 0; la página de notificaciones sigue compilando
sin la interface local.

### Step 2: SystemResource compartido

Un solo resource para `GET /system` con params; ambos modales lo consumen.
Los dos modales hoy piden lo mismo — verificar params exactos de cada uno antes
de unificar; si difieren, el método acepta los params.

**Verify**: `pnpm build` exit 0.

### Step 3: UserCombobox

Mover `searchUsers` a `src/modules/user/services/` (al resource existente del
módulo user si lo hay). PRESERVAR el comentario sobre `sortableBy` (es
conocimiento operativo: ordenar da 422).

**Verify**: `pnpm build` exit 0.

### Step 4: Gate global

**Verify**:
`grep -rn "apiClient\." frontend/src/modules --include="*.tsx" | grep -v "/services/"` → 0 matches.

## Test plan

- Si existen tests de estos módulos, deben pasar sin cambios.
- Smoke manual: centro de notificaciones (listar, marcar una, marcar todas),
  modal de sistemas en Tenants y en Planes, buscador de personas al asociar a
  unidad.

## Done criteria

- [ ] `grep -rn "apiClient\." frontend/src/modules --include="*.tsx" | grep -v "/services/"` → 0
- [ ] `grep -n "interface INotificationUser" frontend/src/modules/notification/NotificationListPage.tsx` → 0 (vive en schema.ts)
- [ ] `pnpm build` exit 0; `pnpm test` pass; `pnpm lint` sin errores nuevos
- [ ] `plans/README.md` row updated

## STOP conditions

- Los requests resultantes difieren de los originales (URL, método, body) —
  comparar con los excerpts; este plan NO cambia el contrato con el backend.
- `src/modules/system/` no existe o tiene una estructura distinta a la asumida.

## Maintenance notes

- Regla para PRs futuros: `apiClient` solo se importa desde `services/` o
  `shared/`. Follow-up barato: regla ESLint `no-restricted-imports` para
  `@/shared/api/apiClient` fuera de esos paths.
