# Plan 032: Partir `ConfigurationPage` (461 líneas, arrow complejidad 33) en secciones

> **Executor instructions**: Follow this plan step by step. Run every
> verification command before moving on. On any STOP condition, stop and report.
> When done, update your row in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C frontend diff --stat e5296c9..HEAD -- src/modules/configuration/`
> Ante mismatch con los excerpts, STOP.

## Status

- **Priority**: P3
- **Effort**: M
- **Risk**: MED (solo admin la usa, pero toca la config operativa del clúster)
- **Depends on**: none (independiente; puede ir después de 024 para diff limpio)
- **Category**: tech-debt
- **Planned at**: frontend submodule `e5296c9`, 2026-06-12

## Why this matters

`ConfigurationPage` es un god component de 461 líneas con complejidad 33 en su
arrow principal (lint 2026-06-12, `27:37`): maneja en un solo cuerpo la config
general (datos legales de company), operacional (timezone/tipo), de cobranza,
plantillas de notificación, branding y documentos legales — 5 mutations y 4
estados de edición paralelos (`generalEdits`, `operationalEdits`,
`collectionEdits`, `templateEdits`) con el patrón overlay
`{ ...server, ...edits }` repetido a mano. Agregar una sección nueva hoy
significa tocar todo el archivo.

## Current state

`src/modules/configuration/ConfigurationPage.tsx` (excerpt líneas 27–59):

```tsx
export const ConfigurationPage = () => {
  const canUpdate = usePermissions('update.configuration')
  const {
    general, operational, collection, plan, templates, legalDocuments,
    saveMutation, brandingMutation, templatesMutation, restoreTemplatesMutation, uploadLegalMutation,
  } = useConfiguration()

  const [legalType, setLegalType] = useState<string>(LEGAL_TYPES[0].value)
  // ... legalTitle, legalVisible
  // ── overlay de ediciones por tab: lo mostrado = servidor + ediciones locales ──
  const [generalEdits, setGeneralEdits] = useState<GeneralConfig>({})
  const [operationalEdits, setOperationalEdits] = useState<OperationalConfig>({})
  const [collectionEdits, setCollectionEdits] = useState<CollectionSettings>({})
  const [templateEdits, setTemplateEdits] = useState<Record<string, Partial<NotificationTemplate>>>({})

  const generalForm: GeneralConfig = { ...general, ...generalEdits }
  // ... operationalForm, collectionForm, templatesForm
```

Más abajo: `saveGeneral` / `saveOperational` / `saveCollection` /
`saveTemplates` / `restoreTemplates` / `onPickBranding` / `onPickLegal` y un
JSX largo de tabs/secciones. El hook `useConfiguration()` (en
`src/modules/configuration/hooks/` — localizar) ya centraliza datos y
mutations: ESO no cambia.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Lint del módulo | `cd frontend && pnpm exec eslint src/modules/configuration/` | exit 0 al terminar |
| Build / Tests | `pnpm build` / `pnpm test` | exit 0 / pass |

## Scope

**In scope**:
- `src/modules/configuration/ConfigurationPage.tsx`
- Archivos nuevos en `src/modules/configuration/components/` (secciones)
- `src/modules/configuration/hooks/useSectionEdits.ts` (create — helper genérico del patrón overlay)

**Out of scope**:
- `useConfiguration()` y los endpoints — sin cambios.
- El backend `ConfigurationController` — nada de backend acá.
- Cambiar UX/diseño de las tabs.

## Git workflow

- Commits dentro del submódulo `frontend/`, uno por sección extraída.
  Ej: `refactor(configuration): extract GeneralConfigSection`.

## Steps

### Step 1: Helper genérico del patrón overlay

`useSectionEdits<T>(server: T)` que devuelva
`{ form, setField, edits, reset }` con `form = { ...server, ...edits }` —
captura el patrón repetido 4 veces. Tipado estricto, sin `any`.

**Verify**: `pnpm build` exit 0.

### Step 2: Extraer 4 secciones + branding/legal

Componentes: `GeneralConfigSection`, `OperationalConfigSection`,
`CollectionConfigSection`, `NotificationTemplatesSection`,
`LegalDocumentsSection` (incluye branding si el JSX los agrupa así — respetar
la agrupación visual ACTUAL de las tabs). Cada sección: recibe su slice de
datos + la mutation correspondiente por props (o llama `useConfiguration()` —
elegir UNA convención y aplicarla igual en todas; preferir props para que el
hook se llame una vez en la página), usa `useSectionEdits` internamente, y
posee su botón Guardar con su `isPending`.

`ConfigurationPage` queda como shell de tabs (~80–120 líneas) con
`canUpdate` pasado a cada sección.

**Verify** (tras cada sección): `pnpm build` exit 0 + smoke de esa tab.

### Step 3: Gates

**Verify**: `pnpm exec eslint src/modules/configuration/` → exit 0 (cero
complexity); `pnpm test` pass.

### Step 4: Smoke manual completo

Como Administrator: editar y guardar cada tab (general, operacional, cobranza,
plantillas — incl. restaurar plantillas, branding, documento legal con
visibilidad) y verificar persistencia tras reload.

## Test plan

- Opcional pero recomendado: test unitario de `useSectionEdits` (overlay,
  setField, reset) — es genérico y barato de testear.
- Tests existentes del proyecto verdes sin cambios.

## Done criteria

- [ ] `pnpm exec eslint src/modules/configuration/` → exit 0
- [ ] `ConfigurationPage.tsx` ≤ ~150 líneas (shell de tabs)
- [ ] Cada mutation se dispara con el MISMO payload que antes (los `payload` de `saveMutation.mutate` se mueven literales a su sección)
- [ ] `pnpm build` exit 0; `pnpm test` pass
- [ ] `plans/README.md` row updated

## STOP conditions

- Los excerpts no coinciden con el código vivo.
- Una sección necesita estado de OTRA sección (acople real no visible en el
  excerpt): reportar el acople antes de inventar un contexto compartido.
- Cualquier payload de guardado difiere del original.

## Maintenance notes

- Secciones de configuración nuevas = componente nuevo + tab en el shell; el
  patrón overlay viene gratis de `useSectionEdits`.
- Deuda conocida que este plan NO resuelve (anotada en el audit): no hay
  confirmación al cambiar de tab con edits sin guardar — candidata a follow-up
  de UX sobre `useSectionEdits` (expone `hasChanges` trivialmente).
