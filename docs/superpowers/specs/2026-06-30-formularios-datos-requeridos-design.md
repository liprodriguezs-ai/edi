# Diseño: Indicador de campos requeridos en formularios

**Fecha:** 2026-06-30
**Estado:** Diseño aprobado — pendiente de revisión del spec por el usuario.

## Problema

Los formularios de la app no comunican de forma consistente qué campos son obligatorios.
Faltan dos cosas: (1) garantizar que TODO campo requerido muestre su asterisco `*`, y
(2) una leyenda «* Datos requeridos» que explique el símbolo.

## Hallazgos (estado actual del código)

- El componente compartido **`FieldLabel`** (`frontend/src/components/ui/field.tsx`) **YA** tiene
  un prop `required` que pinta ` *` en rojo (`text-destructive`, vía pseudo-elemento `::after`) y
  setea `aria-required`. **NO se debe modificar** — `components/ui/` es intocable (regla 7 de la skill).
  La mayoría de los forms ya usan `<FieldLabel required>`.
- La **fuente de verdad** de "requerido" es el `schema.ts` de Zod de cada módulo:
  - Requerido: `z.string().min(1, '...')` (o `.positive()`/`.min(1)` en números).
  - Opcional: `.optional()` / `.nullable()` / `.optional().or(z.literal(''))`.
  - Condicional: `.superRefine(...)` (ej. `unit` → torre/piso requeridos según `unit_type`).
- La leyenda «* Datos requeridos» **NO existe** en ningún form (0 ocurrencias en todo el front).
- No hay capa de i18n: el texto de UI es español hardcodeado en el JSX.
- Hay **35 forms internos** + **5 de auth**. Los de auth quedan **FUERA de alcance**.

## Decisiones (acordadas con el usuario)

1. **Asterisco por campo:** usar el prop `required` del `FieldLabel` (ya existe). Auditar y completar.
2. **Leyenda:** sí se agrega; ubicada **ARRIBA**, bajo el título del form.
3. **Implementación de la leyenda:** componente compartido `<RequiredFieldsLegend />`
   (decisión técnica del implementador, por DRY: el `<p>` vive en 1 solo archivo, no replicado 35
   veces; el call-site es más corto). El usuario había barajado inline; se elige componente por la
   política DRY del proyecto. Reversible si el usuario insiste en inline.
4. **Alcance:** SOLO los 35 forms internos. Los 5 forms de auth quedan fuera.
5. **Documentación:** convención en `.agents/skills/react-frontend-architecture/SKILL.md` §2.8 + nota de enforcement
   en la memoria de `@react-frontend-reviewer`.

## Diseño

### 1. Componente de leyenda (nuevo)

`frontend/src/shared/components/RequiredFieldsLegend.tsx`:

```tsx
export function RequiredFieldsLegend() {
  return (
    <p className="text-sm text-muted-foreground">
      <span className="text-destructive">*</span> Datos requeridos
    </p>
  )
}
```

- El `*` en `text-destructive` para igualar el color del asterisco de los campos.
- Se renderiza como **primer hijo del cuerpo del form** (arriba, antes del primer `<FieldGroup>`).
- El implementador confirma que los tokens `text-muted-foreground` / `text-destructive` existen en
  el tema (el segundo lo usa ya `FieldLabel`).

### 2. Auditoría del asterisco (schema ↔ label)

Por cada uno de los 35 forms:

1. Leer su `schema.ts` (o el schema inline) e identificar los campos requeridos en Zod.
2. En el JSX, garantizar que el `<FieldLabel>` de cada campo requerido tenga `required`.
   **Agregarlo donde falte.**
3. Confirmar que los campos opcionales NO tengan `required` (evitar falsos positivos).
4. Casos especiales:
   - **Requerido condicional** (`superRefine`, ej. `unit` torre/piso): marcar con `required` cuando
     es requerido en el caso típico del flujo. Si es genuinamente opcional según estado, se deja sin
     `*` y se anota como excepción.
   - **Controller vs register:** el patrón de label es idéntico (`<FieldLabel required>`), sin diferencia.

### 3. Inserción de la leyenda

`import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'` y
`<RequiredFieldsLegend />` como primer elemento del cuerpo del form, en los 35 forms.
En **`CatalogCrudModule`** se agrega UNA vez → cubre todos los módulos de catálogo automáticamente.

### 4. Documentación

- `.agents/skills/react-frontend-architecture/SKILL.md` §2.8: agregar la regla — todo form marca requeridos con
  `<FieldLabel required>` (derivado del schema Zod) y muestra `<RequiredFieldsLegend />` arriba.
- Memoria de `@react-frontend-reviewer`: anti-pattern — form con campo requerido-en-Zod sin `required`
  en su label, o form sin `<RequiredFieldsLegend />`.

## Alcance — inventario (35 forms internos)

- **access:** `AccessNowPage.tsx`, `components/CorrectionModal.tsx`, `components/EntryRequestModal.tsx`, `components/VisitorFormModal.tsx`
- **announcement:** `components/AnnouncementComposeModal.tsx`
- **bankAccount:** `BankAccountListPage.tsx`
- **boardPeriod:** `components/StartPeriodModal.tsx`
- **chargeBatch:** `ChargeBatchFormPage.tsx`
- **chargeConcept:** `ChargeConceptFormModal.tsx`
- **chargePolicy:** `ChargeRuleFormPage.tsx`
- **chat:** `components/ChannelFormModal.tsx`
- **collaborator:** `CollaboratorForm.tsx`
- **communityProject:** `CommunityProjectListPage.tsx`, `components/ContributeModal.tsx`
- **company:** `CompanyForm.tsx`
- **expense:** `components/ExpenseFormModal.tsx`
- **package:** `PackageForm.tsx`, `PackagePickupModal.tsx`, `components/BulkIntakeForm.tsx`
- **payment:** `components/RegisterPaymentSheet.tsx`, `components/SubmitPaymentSheet.tsx`
- **provider:** `ProviderListPage.tsx`
- **residentInvitation:** `BlockResidentModal.tsx`, `InviteResidentModal.tsx`
- **role:** `RoleForm.tsx`
- **subscriptionPlan:** `SubscriptionPlanForm.tsx`
- **tenant:** `TenantForm.tsx`
- **unit:** `UnitForm.tsx`, `components/AssociatePersonModal.tsx`, `components/ChangeOwnerDialog.tsx`, `components/VehicleFormModalStandalone.tsx`
- **user:** `UserForm.tsx`, `UserList.tsx`
- **shared:** `shared/components/catalog/CatalogCrudModule.tsx` (genérico → cubre todos los catálogos), `shared/components/SearchList.tsx` (verificar si tiene campos requeridos)

**Fuera de alcance:** los 5 forms de auth (`login-form`, `signup-form`, `forgot-password-form`,
`reset-password-form`, `accept-invitation-form`); cualquier cambio a `components/ui/`.

## Verificación

1. `@react-frontend-reviewer` sobre el diff.
2. `pnpm build` (tsc) en verde.
3. Revisión visual de 2-3 forms representativos.

## Riesgos

- **Drift de naming** entre schema y JSX (un campo de Zod sin un `FieldLabel` visible — campo oculto,
  derivado o de solo-lectura). Mitigación: el implementador verifica campo por campo, no a ciegas.
- **Requeridos condicionales:** un `*` fijo puede "mentir" cuando el campo es opcional en cierto estado.
  Mitigación: tratarlos caso a caso (ver §2.4).
