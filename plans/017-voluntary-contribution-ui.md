# Plan 017 — UI de aportación voluntaria a proyecto comunitario

- **Status**: TODO
- **Leverage**: Alta (feature funcional faltante, confirmada por verificación E2E con rol resident)
- **Effort**: M
- **Risk**: Media (toca flujo de dinero — pero el backend ya valida y blinda todo; el frontend solo arma la UI sobre un endpoint existente)
- **Written against**: frontend `a96dd14` (+ commit local `580a356` del plan 015), backend `a96dd14`
- **Scope**: SOLO frontend. El backend ya está completo y NO se toca.

---

## Problema (evidencia)

Un proyecto comunitario **voluntario** acepta aportaciones libres de las unidades vía `POST /community-project/{id}/contribute`. El backend está completo: endpoint, validación, autorización, servicio que registra el cargo + pago con folio. El resource del frontend **ya tiene** el método `contribute()`.

**Pero NO existe ninguna UI para invocarlo.** Verificado E2E con `resident@encinos.test`:
- `/project-contribution` (Aportaciones) lista las aportaciones por unidad en **solo lectura** — sin acción para aportar.
- `/community-project/{id}` (detalle del proyecto) muestra meta/recaudado/aportaciones por unidad — **sin botón "Aportar"** por ningún lado.

Resultado: un residente ve que su unidad tiene una aportación "Pendiente" de $500 a "Pintura de fachadas" (voluntario, activo) pero **no puede aportar**. Es la clase de "te has saltado cosas" que el usuario reportó.

---

## Contrato backend (NO modificar — solo referencia)

`POST /api/community-project/{id}/contribute`
Archivo: `backend/Modules/Projects/app/Http/Controllers/CommunityProjectController.php:168-217`

Validación (`$request->validate`):
```php
'amount'            => ['required', 'integer', 'min:1', 'max:999999999'], // MINOR UNITS (centavos)
'payment_method_id' => ['required', 'uuid', 'exists:payment_method,id'],
'bank_account_id'   => ['required', 'uuid', Rule::exists('bank_account','id')->where('tenant_id', tenant('id'))],
'unit_id'           => ['nullable', 'uuid'],
'paid_at'           => ['nullable', 'date'],
'reference'         => ['nullable', 'string', 'max:255'],
```

Reglas de negocio (devuelven 422 con `{ message }`):
- Proyecto obligatorio → `"Las aportaciones de un proyecto obligatorio se pagan a través de su cargo."`
- Proyecto no en `ACTIVE` ni `FULLY_FUNDED` → `"El proyecto no está activo. No se aceptan aportaciones."`
- Sin aportación de la unidad del caller → `"No existe una aportación de tu unidad para este proyecto."`

Autorización (`CommunityProjectController.php:289-306`):
- Gate de ruta: `read.community_project` (los residentes lo tienen).
- Mutación: quien tenga `update.project_contribution` (mesa/admin) puede aportar a **cualquier** unidad; el resto solo a unidades donde tenga un `unit_user` activo (sin `end_date`). Si no, **403**.

Resolución de `unit_id` (`CommunityProjectController.php:262-281`):
- Si el caller envía `unit_id`, se usa tal cual.
- Si lo omite y pertenece a exactamente UNA unidad objetivo, se infiere.
- Si pertenece a varias (ambiguo) → 422. ⇒ **La UI siempre debe enviar `unit_id` explícito** (lo tenemos en la fila de la tabla).

Respuesta 200: `{ "message": "Aportación registrada exitosamente.", "data": { ...ProjectContribution con contributionStatus } }`.

### GOTCHAS (críticos)
1. **`amount` es MINOR UNITS (centavos).** El input del usuario va en pesos → convertir con `pesosToMinorUnits()` antes de enviar. El tipo `amount: number` no lo evidencia.
2. **`contribute()` devuelve el body crudo** `{ message, data }` (NO `data.data`). Para el toast usar `response.message`.
3. **Enviar siempre `unit_id`** (de la fila), nunca confiar en inferencia.
4. Mostrar la acción SOLO si el proyecto es **voluntario** (`participation_type_id === PARTICIPATION_TYPE.VOLUNTARY`) Y está en estado contribuible (`ACTIVE` o `FULLY_FUNDED`).
5. Para no mostrar un botón que dará 403, gatear por fila: visible si el usuario tiene `update.project_contribution` **o** la `unit_id` de la fila está entre sus unidades (`UnitResource.my()`).

---

## Estado actual del frontend (lo que ya existe)

- `frontend/src/modules/communityProject/services/CommunityProjectResource.ts:30-33` — `contribute(id, payload)` YA existe. **Ampliar su tipo** para incluir `paid_at?` y `reference?`.
- `frontend/src/modules/communityProject/schema.ts` — `PARTICIPATION_TYPE`, `COMMUNITY_PROJECT_STATUS`, `ICommunityProject`, `IProjectContribution`. **Agregar** `contributeFormSchema` + `ContributeFormValues`.
- `frontend/src/modules/communityProject/CommunityProjectDetailPage.tsx` — detalle con la tabla "Aportaciones por unidad" (líneas 174-214). **Punto de inserción** del botón por fila.
- Patrón de referencia 1 (campos de pago): `frontend/src/modules/payment/components/RegisterPaymentSheet.tsx` — radiogroup `PAYMENT_METHOD`, select `BANK_ACCOUNT`, input pesos → `pesosToMinorUnits`.
- Patrón de referencia 2 (modal de acción simple): `frontend/src/modules/access/components/VisitorFormModal.tsx` — Dialog + RHF + zodResolver + useMutation + toast.
- `UnitResource.my(): Promise<IUnit[]>` (`frontend/src/modules/unit/services/UnitResource.ts:12`) — unidades del usuario.
- `usePermissions(key): boolean` (`frontend/src/shared/hooks/usePermissions.ts:12`).
- Helpers de dinero: `pesosToMinorUnits`, `formatMinorUnits`, `moneyToMinorUnits`, `displayMoney` (`frontend/src/shared/utils/money.ts`).
- Catálogos: `CatalogType.PAYMENT_METHOD`, `CatalogType.BANK_ACCOUNT` (`frontend/src/shared/hooks/useCatalogs.ts`).

---

## Implementación (pasos ordenados)

### Paso 1 — Ampliar el tipo de `contribute()` en el resource
Archivo: `frontend/src/modules/communityProject/services/CommunityProjectResource.ts`
En la firma del custom method (línea 10), cambiar el payload a:
```ts
contribute: (_id: string, _payload: {
  amount: number
  payment_method_id: string
  bank_account_id: string
  unit_id?: string
  paid_at?: string
  reference?: string
}) => Promise<{ message?: string; data?: unknown }>
```
La implementación (`apiClient.post(...).then(r => r.data)`) ya devuelve el body; tiparla como `{ message?, data? }`.

### Paso 2 — Schema zod de la aportación
Archivo: `frontend/src/modules/communityProject/schema.ts` (al final).
Montos en PESOS (igual que `communityProjectFormSchema`):
```ts
/** Monto en PESOS — se convierte a minor units antes de enviar. */
export const contributeFormSchema = z.object({
  amount: z.number({ message: 'El monto es requerido' }).positive('El monto debe ser mayor a 0'),
  payment_method_id: z.string().min(1, 'El método de pago es requerido'),
  bank_account_id: z.string().min(1, 'La cuenta destino es requerida'),
  paid_at: z.string().min(1, 'La fecha es requerida'),
  reference: z.string().max(255).optional(),
})

export type ContributeFormValues = z.infer<typeof contributeFormSchema>
```

### Paso 3 — Componente `ContributeModal`
Nuevo archivo: `frontend/src/modules/communityProject/components/ContributeModal.tsx`
Modelar sobre `VisitorFormModal.tsx` (Dialog) reutilizando los campos de pago de `RegisterPaymentSheet.tsx`. Props:
```ts
interface ContributeModalProps {
  isOpen: boolean
  onClose: () => void
  projectId: string
  unitId: string
  unitLabel: string
  expectedMinor: number        // de contribution.expected_amount (para mostrar referencia)
  onContributed: () => void     // invalida queries del detalle
}
```
Contenido:
- `useForm<ContributeFormValues>` con `zodResolver(contributeFormSchema)`, `defaultValues` en `useMemo` (`amount: 0`, `payment_method_id: PAYMENT_METHOD.CASH` de `@/modules/payment/schema`, `bank_account_id: ''`, `paid_at: hoy ISO yyyy-mm-dd`, `reference: ''`). `useEffect([isOpen]) reset(defaultValues)`.
- `useCatalogs(useMemo(() => ({ [CatalogType.PAYMENT_METHOD]: { _sort: 'order' }, [CatalogType.BANK_ACCOUNT]: { _sort: 'name' } }), []))`.
- Encabezado: "Aportar a {unitLabel}" + texto auxiliar "Esperado: {formatMinorUnits(expectedMinor)}".
- Campos: monto (input pesos con prefijo `$`, `setValueAs` Number), método de pago (radiogroup como RegisterPaymentSheet), cuenta destino (select BANK_ACCOUNT), fecha (`type="date"`), referencia (input opcional).
- `useMutation`:
  ```ts
  mutationFn: (values: ContributeFormValues) => CommunityProjectResource.contribute(projectId, {
    amount: pesosToMinorUnits(values.amount),
    payment_method_id: values.payment_method_id,
    bank_account_id: values.bank_account_id,
    unit_id: unitId,
    paid_at: values.paid_at,
    reference: values.reference || undefined,
  }),
  onSuccess: (res) => { toast.success(res?.message ?? 'Aportación registrada'); onContributed(); onClose() },
  onError: (e) => toast.error(e instanceof ApiError ? formatApiErrorMessage(e) : 'No se pudo registrar la aportación'),
  ```
- Botón submit deshabilitado durante `mutation.isPending`. Todos los campos `disabled={mutation.isPending}`.

### Paso 4 — Botón "Aportar" por fila en el detalle
Archivo: `frontend/src/modules/communityProject/CommunityProjectDetailPage.tsx`
1. Imports: `ContributeModal`, `PARTICIPATION_TYPE`, `usePermissions`, `UnitResource`, `useQueryClient` (ya está).
2. Estado: `const [contributeRow, setContributeRow] = useState<IProjectContribution | null>(null)`.
3. Derivar contribuibilidad:
   ```ts
   const isVoluntary = typedProject.participation_type_id === PARTICIPATION_TYPE.VOLUNTARY
   const isContributable = isVoluntary && (
     typedProject.community_project_status_id === COMMUNITY_PROJECT_STATUS.ACTIVE ||
     typedProject.community_project_status_id === COMMUNITY_PROJECT_STATUS.FULLY_FUNDED
   )
   const canContributeAny = usePermissions('update.project_contribution')
   const { data: myUnits = [] } = useQuery({ queryKey: ['unit','my'], queryFn: () => UnitResource.my(), enabled: isContributable && !canContributeAny })
   const myUnitIds = useMemo(() => new Set(myUnits.map(u => u.id)), [myUnits])
   const canContributeRow = (c: IProjectContribution) =>
     isContributable && !c.declined && (canContributeAny || myUnitIds.has(c.unit_id))
   ```
   ⚠️ `usePermissions` y `useQuery` son hooks → llamarlos en el cuerpo del componente ANTES del `if (isLoading || !project) return`. Mover ese early-return o las derivaciones de proyecto con cuidado: como `typedProject` se calcula después del guard, calcular `isVoluntary`/`isContributable` también después; pero los hooks (`usePermissions`, `useQuery`) deben ir arriba con valores que toleren `project` undefined. Solución: llamar `usePermissions('update.project_contribution')` siempre; el `useQuery` de unidades con `enabled` que dependa de un flag derivado tras el guard NO sirve (orden de hooks). En su lugar, calcular un `participationTypeId = (project as ICommunityProject | undefined)?.participation_type_id` arriba y derivar `isContributable` arriba también, todo tolerante a undefined. Mantener TODOS los hooks por encima del early-return.
4. Añadir columna de acción a la tabla (header + celda) SOLO si `isContributable`:
   - Header: `{isContributable && <TableHead className="text-right"></TableHead>}`
   - Celda por fila: botón "Aportar" (`size="sm"`, rounded-full) `onClick={() => setContributeRow(contribution)}`, visible si `canContributeRow(contribution)`.
5. Render del modal (una sola instancia):
   ```tsx
   {contributeRow && (
     <ContributeModal
       isOpen={Boolean(contributeRow)}
       onClose={() => setContributeRow(null)}
       projectId={id}
       unitId={contributeRow.unit_id}
       unitLabel={contributeRow.unit?.full_identifier ?? contributeRow.unit?.number ?? 'unidad'}
       expectedMinor={moneyToMinorUnits(contributeRow.expected_amount ?? 0)}
       onContributed={() => void queryClient.invalidateQueries({ queryKey: ['project'] })}
     />
   )}
   ```

---

## Out of scope (NO tocar)
- Backend (controller, service, rutas) — está completo.
- `/project-contribution` (ProjectContributionListPage) — opcional dejar como solo-lectura; la acción vive en el detalle del proyecto.
- El refactor Modal→Sheet pre-existente en el working tree (rama `feat/salvia-auth-redesign`) — ajeno a este plan.
- Lógica de "declinar" aportación (ya existe vía `ProjectContributionResource.decline`).

## Done criteria (verificables)
1. `pnpm exec tsc -b --noEmit` → exit 0.
2. `pnpm exec vitest run src/modules/communityProject` → tests verdes (ver abajo).
3. E2E con Playwright como `resident@encinos.test`:
   - En `/community-project/{voluntario-activo}` aparece botón "Aportar" en la fila de SU unidad (Casa 1).
   - Click → modal "Aportar a Casa 1" con monto/método/cuenta/fecha.
   - Llenar monto 250, método, cuenta → "Aportar" → `POST /community-project/{id}/contribute` devuelve 200 → toast "Aportación registrada exitosamente." → modal cierra → la fila pasa a "Aportado 250,00 MXN".
   - Recaudado del KPI sube.
4. Limpiar el dato de prueba creado (revertir/anular la aportación de prueba, o aceptar que queda como registro de QA — coordinar; para verificación, usar un monto chico y, si es posible, borrarlo por DB tras la prueba).

## Test plan (vitest)
Crear `frontend/src/modules/communityProject/schema.test.ts` (modelar sobre `access/schema.test.ts`):
- `contributeFormSchema` acepta una aportación válida.
- Rechaza monto 0 / negativo.
- Rechaza sin `payment_method_id` / sin `bank_account_id` / sin `paid_at`.

## Nota de mantenimiento
- Si en el futuro el endpoint deja de requerir `bank_account_id` (p.ej. aportaciones "pledge" sin pago inmediato), este modal cambia de "registrar pago" a "comprometer aportación". Hoy crea un pago real con folio.
- El gating por fila depende de `UnitResource.my()`; si cambia el contrato de unidades del usuario, revisar `myUnitIds`.
