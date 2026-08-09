---
name: react-frontend-architecture
description: Frontend architecture patterns, component structure, and conventions for a React/TypeScript application. Use when creating or modifying React components, modules, forms, lists, stores, or any frontend code.
tags: [react, typescript, frontend, tailwind, zustand, tanstack-query]
metadata:
  author: Rodriguez
  version: "1.0"
---

## 1. ÁRBOL DE ARCHIVOS FRONTEND (`src/`)

```
src/
├── App.tsx                                    # Router principal con rutas públicas/protegidas
├── main.tsx                                   # Entry point: React Query, SessionProvider, Router
├── index.css                                  # Estilos globales Tailwind v4
│
├── app/
│   ├── layout/
│   │   ├── DashboardLayout.tsx                # Layout principal: Sidebar + Header + Content
│   │   └── useLayoutContext.ts                # Contexto para full-width mode
│   ├── router/
│   │   ├── ProtectedRoute.tsx                 # Guard: requiere autenticación
│   │   ├── PublicOnlyRoute.tsx                # Guard: redirige si autenticado
│   │   ├── PermissionRoute.tsx                # Guard: requiere permiso específico
│   │   └── SubscriptionGuard.tsx              # Guard: requiere suscripción activa
│   ├── AccessDeniedPage.tsx
│   └── dashboard/
│       └── data.json
│
├── components/
│   ├── ui/                                    # ÁTOMOS — Componentes shadcn/Radix (NO MODIFICAR)
│   │   ├── alert-dialog.tsx
│   │   ├── avatar.tsx
│   │   ├── badge.tsx
│   │   ├── button.tsx
│   │   ├── calendar.tsx
│   │   ├── card.tsx
│   │   ├── checkbox.tsx
│   │   ├── collapsible.tsx
│   │   ├── dialog.tsx
│   │   ├── dropdown-menu.tsx
│   │   ├── empty.tsx
│   │   ├── field.tsx                          # Field, FieldLabel, FieldError, FieldGroup, FieldSet
│   │   ├── input.tsx
│   │   ├── label.tsx
│   │   ├── pagination.tsx
│   │   ├── popover.tsx
│   │   ├── radio-group.tsx
│   │   ├── select.tsx
│   │   ├── separator.tsx
│   │   ├── sheet.tsx
│   │   ├── sidebar.tsx
│   │   ├── skeleton.tsx
│   │   ├── slider.tsx
│   │   ├── switch.tsx
│   │   ├── table.tsx
│   │   ├── tabs.tsx
│   │   ├── textarea.tsx
│   │   └── tooltip.tsx
│   ├── app-sidebar.tsx                        # Sidebar con navegación por roles
│   ├── site-header.tsx
│   ├── nav-main.tsx
│   ├── nav-secondary.tsx
│   ├── nav-documents.tsx
│   ├── nav-user.tsx
│   ├── kanban.tsx
│   ├── login-form.tsx
│   ├── signup-form.tsx
│   ├── forgot-password-form.tsx
│   ├── reset-password-form.tsx
│   └── accept-invitation-form.tsx
│
├── shared/
│   ├── api/
│   │   ├── apiClient.ts                       # Axios config, interceptors, refresh token
│   │   ├── resourceApi.ts                     # createCrudApi() — factory CRUD genérico
│   │   └── queryClient.ts                     # React Query config
│   ├── auth/
│   │   ├── authService.ts                     # login, register, refresh, logout
│   │   ├── sessionStore.ts                    # Zustand: profile, tokens, isAuthenticated
│   │   ├── useSession.ts                      # Hook: profile, abilities, roles, login/logout
│   │   ├── SessionProvider.tsx                # Restaura sesión al montar
│   │   └── Session.ts                         # Tipos de sesión
│   ├── components/
│   │   ├── atoms/
│   │   │   ├── PageHeader.tsx                 # Título + descripción de página
│   │   │   ├── ProfileIdentity.tsx            # Avatar + nombre
│   │   │   └── ObfuscatedText.tsx             # Texto ofuscado
│   │   ├── molecules/
│   │   │   ├── ActionButtonGroup.tsx          # Botones de acción (edit/delete/detail)
│   │   │   ├── TableLoadingState.tsx          # Skeleton para tablas
│   │   │   └── TableEmptyState.tsx            # Estado vacío de tabla
│   │   ├── ConfirmDialog.tsx                  # Diálogo de confirmación (delete, etc.)
│   │   ├── DatePicker.tsx                     # Selector de fecha
│   │   ├── Modal.tsx                          # Wrapper modal genérico
│   │   ├── Pagination.tsx                     # Paginación con selector de tamaño
│   │   ├── PasswordStrength.tsx               # Indicador de fuerza de contraseña
│   │   ├── PermissionGuard.tsx                # Render condicional por permiso
│   │   ├── PermissionsModal.tsx               # Modal "sin permisos"
│   │   └── SearchList.tsx                     # Barra de búsqueda + filtros colapsables
│   ├── hooks/
│   │   ├── useBaseList.ts                     # CORE: lógica de listado (paginación, filtros, sort)
│   │   ├── useBaseCrud.ts                     # CORE: lógica CRUD (create, update, delete)
│   │   ├── useCatalogs.ts                     # Carga catálogos dinámicos (POST /selects)
│   │   ├── usePermissions.ts                  # Verifica permisos del usuario
│   │   ├── useRoles.ts                        # Verifica roles del usuario
│   │   ├── useFilteredNavItems.ts             # Filtra navegación por rol/permiso
│   │   ├── useInitOnce.ts                     # Ejecuta una vez al montar
│   │   ├── usePermissionsModal.ts             # Estado del modal de permisos
│   │   └── useToast.ts                        # Toast notifications (Sonner)
│   ├── interfaces/
│   │   ├── Entity.ts                          # IEntity, IMedia, IFormProps, ERoleUserSlug
│   │   └── list.types.ts                      # ISearchParams, IPagination, FilterOperator
│   ├── store/
│   │   └── EntityStore.ts                     # createEntityStore() — factory Zustand
│   ├── enums/
│   │   └── CrudMode.ts                        # CREATE | EDIT
│   ├── config/
│   │   └── appConfig.ts                       # apiBaseUrl, pageSize
│   ├── constants/
│   │   └── passwordRequirements.ts
│   └── utils/
│       ├── dateHelper.ts                      # displayFormatDate, etc.
│       └── displayInitials.ts                 # Obtener iniciales de nombre
│
├── hooks/
│   └── use-mobile.ts                          # Breakpoint mobile (768px)
│
├── lib/
│   └── utils.ts                               # cn() — clsx + twMerge
│
├── modules/                                   # CADA MÓDULO sigue la misma estructura
│   ├── document/                              # EJEMPLO REFERENCIA
│   │   ├── schema.ts                          # Zod schema + interface IDocument
│   │   ├── DocumentListPage.tsx               # Page wrapper
│   │   ├── DocumentList.tsx                   # Lista con tabla + búsqueda + paginación
│   │   ├── DocumentForm.tsx                   # Formulario (create/edit)
│   │   ├── DocumentFormModal.tsx              # Modal que envuelve el formulario
│   │   ├── components/
│   │   │   └── DocumentTableRow.tsx           # Fila de tabla (memoizada)
│   │   ├── hooks/
│   │   │   └── useDocumentList.ts             # Hook de listado del módulo
│   │   ├── services/
│   │   │   └── DocumentResource.ts            # API CRUD del módulo
│   │   └── store/
│   │       └── documentStore.ts               # Zustand store del módulo
│   ├── client/                                # Módulo con detail page
│   │   ├── schema.ts
│   │   ├── ClientListPage.tsx
│   │   ├── ClientList.tsx
│   │   ├── ClientForm.tsx
│   │   ├── ClientFormModal.tsx
│   │   ├── ClientDetailPage.tsx               # Página de detalle (ruta /:id)
│   │   ├── ClientDetail.tsx                   # Vista detalle con tabs
│   │   ├── components/
│   │   │   ├── ClientTableRow.tsx
│   │   │   └── ClientProfileCard.tsx
│   │   ├── hooks/
│   │   │   ├── useClientList.ts
│   │   │   └── useClientDetail.ts             # React Query para detalle
│   │   ├── services/
│   │   │   └── ClientResource.ts
│   │   └── store/
│   │       └── clientStore.ts
│   ├── area/
│   ├── audit/
│   ├── administrativeMinute/
│   ├── checkoutSubscription/
│   ├── clientMetadata/
│   ├── collaborator/                          # Módulo complejo con import wizard, me/
│   ├── company/
│   ├── contractType/
│   ├── dashboard/
│   ├── documentCollaborator/
│   ├── documentTemplate/
│   ├── documentType/
│   ├── historyClient/
│   ├── historyCollaborator/
│   ├── interaction/
│   ├── jobPosition/
│   ├── note/
│   ├── project/
│   ├── role/
│   ├── stage/
│   ├── subscriptionPlan/
│   ├── tenant/
│   └── user/
│
└── assets/
    └── react.svg
```

---

## 2. FRONTEND: ARQUITECTURA Y PATRONES

### 2.1 Estructura de un Módulo Frontend

Cada módulo en `src/modules/{moduleName}/` sigue esta estructura:

```
{moduleName}/
├── schema.ts                    # Zod schema + interface
├── {ModuleName}ListPage.tsx     # Page wrapper (renderiza el List)
├── {ModuleName}List.tsx         # Lista completa con tabla + búsqueda + paginación
├── {ModuleName}Form.tsx         # Formulario (create/edit)
├── {ModuleName}FormModal.tsx    # Modal que envuelve el formulario
├── components/
│   └── {ModuleName}TableRow.tsx # Fila de tabla memoizada
├── hooks/
│   └── use{ModuleName}List.ts   # Hook de listado específico del módulo
├── services/
│   └── {ModuleName}Resource.ts  # API CRUD
└── store/
    └── {moduleName}Store.ts     # Zustand store
```

**Módulos con detalle** agregan:
```
├── {ModuleName}DetailPage.tsx   # Page wrapper del detalle
├── {ModuleName}Detail.tsx       # Vista de detalle con tabs
└── hooks/
    └── use{ModuleName}Detail.ts # Hook React Query para detalle
```

> **⚡ ¿Es un catálogo SIMPLE? NO crees estos 9 archivos.** Para un CRUD plano de
> pocos campos (Torres, Secciones, Tipos de unidad, etc.) usá el genérico
> `CatalogCrudModule` → módulo de **4 archivos**. Ver **§2.11**. El patrón
> completo de abajo (§2.2–2.10) es para entidades GRANDES o con UI custom.

### 2.2 Ejemplo Completo: schema.ts

```typescript
import type { IEntity } from '@/shared/interfaces/Entity'
import { z } from 'zod'

export const documentFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  description: z.string().optional(),
  type_id: z.string().optional(),
  required: z.boolean().optional(),
  vigency: z.boolean().optional(),
})

export interface IDocument extends IEntity {
  name: string
  description?: string
  type_id: string
  required?: boolean
  vigency?: boolean
  type?: { id: string; name: string }
}

export type DocumentFormValues = z.infer<typeof documentFormSchema>
```

**Reglas del schema:**
- Los mensajes de error SIEMPRE en español.
- La interface extiende `IEntity` (que ya tiene `id`, `created_at`, `tenant_id`, `media`, etc.)
- Relaciones como propiedades opcionales con `?`.
- NO usar types de otros módulos para relaciones simples. Usa objetos inline: `type?: { id: string; name: string }`.
- `FormValues` se infiere del Zod schema.

### 2.3 Ejemplo Completo: Store (Zustand)

```typescript
import type { IDocument } from '@/modules/document/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useDocumentStore = createEntityStore<IDocument>('document')
```

`createEntityStore` ya provee: `entities`, `pagination`, `isLoading`, `isSaving`, `isDeleting`, `notFound`, `selectId`, `currentEntity`, y todos los métodos CRUD del store (`addEntity`, `updateEntity`, `removeEntity`, `setAllEntities`, etc.).

### 2.4 Ejemplo Completo: Resource (API)

```typescript
import type { DocumentFormValues, IDocument } from '@/modules/document/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const DocumentResource = createCrudApi<IDocument, DocumentFormValues>({
  basePath: '/document',
})
```

`createCrudApi` ya provee: `list()`, `search()`, `show()`, `create()`, `update()`, `remove()`, `report()`.

**Si necesitas métodos custom:**

```typescript
export const CollaboratorResource = createCrudApi<ICollaborator, CollaboratorFormValues, Record<string, unknown>, {
  validateImport: (data: FormData) => Promise<unknown>
  batchImport: (data: FormData) => Promise<unknown>
}>({
  basePath: '/collaborator',
  customMethods: (basePath) => ({
    validateImport: async (data: FormData) => {
      const { data: response } = await apiClient.post(`${basePath}/validate-import`, data)
      return response
    },
    batchImport: async (data: FormData) => {
      const { data: response } = await apiClient.post(`${basePath}/batch-import`, data)
      return response
    },
  }),
})
```

### 2.5 Ejemplo Completo: Hook de Listado

```typescript
import type { IDocument } from '@/modules/document/schema'
import { DocumentResource } from '@/modules/document/services/DocumentResource'
import { useDocumentStore } from '@/modules/document/store/documentStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

export function useDocumentList() {
  return useBaseList<IDocument>({
    store: useDocumentStore,
    resource: DocumentResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'name', direction: 'asc' }],
      includes: [
        { relation: 'type' },
      ],
    },
  })
}
```
#### Consideraciones
- siempre que se agregues filtros, includes, order, actualiza el controlador en backend, con las convenciones de orion

`useBaseList` retorna: `getList`, `onSearch`, `onReset`, `onPageChanged`, `onRemove`, `onRestore`, `onReport`, `paramsSearch`, `store`.

### 2.6 Ejemplo Completo: ListPage

```typescript
import { DocumentList } from '@/modules/document/DocumentList'

export const DocumentListPage = () => {
  return <DocumentList />
}
```

### 2.7 Ejemplo Completo: List

```tsx
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { DocumentFormModal } from '@/modules/document/DocumentFormModal'
import { DocumentTableRow } from '@/modules/document/components/DocumentTableRow'
import { useDocumentList } from '@/modules/document/hooks/useDocumentList'
import type { IDocument } from '@/modules/document/schema'
import { useDocumentStore } from '@/modules/document/store/documentStore'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { Plus } from 'lucide-react'
import { useCallback, useState } from 'react'

export const DocumentList = () => {
  const { onPageChanged, onRemove, onSearch, onReset, paramsSearch } = useDocumentList()
  const { entities, pagination, isLoading, isDeleting, isSaving } = useDocumentStore()

  const permissions = useMultiplePermissions({
    canCreate: 'create.document',
    canEdit: 'update.document',
    canDelete: 'delete.document',
    canReport: 'report.document',
    canRestore: 'restore.document',
  })

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selectedEntity, setSelectedEntity] = useState<IDocument | null>(null)
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false)
  const [entityToDelete, setEntityToDelete] = useState<IDocument | null>(null)

  const handleCreate = useCallback(() => {
    setSelectedEntity(null)
    setModalMode(CrudMode.CREATE)
    setModalOpen(true)
  }, [])

  const handleEdit = useCallback((document: IDocument) => {
    setSelectedEntity(document)
    setModalMode(CrudMode.EDIT)
    setModalOpen(true)
  }, [])

  const handleDeleteClick = useCallback((document: IDocument) => {
    setEntityToDelete(document)
    setDeleteConfirmOpen(true)
  }, [])

  const handleDeleteConfirm = useCallback(async () => {
    if (!entityToDelete) return
    try {
      await onRemove(entityToDelete)
      setEntityToDelete(null)
      setDeleteConfirmOpen(false)
    } catch {
      // toast global lo maneja
    }
  }, [entityToDelete, onRemove])

  const handleModalClose = useCallback(() => {
    setModalOpen(false)
    setSelectedEntity(null)
  }, [])

  return (
    <div className="space-y-6">
      {/* Header: responsive — botón se apila en mobile */}
      <div className="flex flex-wrap items-center justify-between gap-4">
        <PageHeader title="Documentos" description="Gestiona los documentos del sistema" />
        <PermissionGuard permission="create.document">
          <Button onClick={handleCreate} className="flex items-center gap-2">
            <Plus className="h-4 w-4" />
            Nuevo documento
          </Button>
        </PermissionGuard>
      </div>

      <section>
        <div className="space-y-6">
          <SearchList
            paramsSearch={paramsSearch}
            onSearch={onSearch}
            onReset={onReset}
            placeholder="Buscar documentos..."
          />

          <section>
            {isLoading ? (
              <TableLoadingState />
            ) : entities.length === 0 ? (
              <TableEmptyState />
            ) : (
              <>
                <div className="overflow-hidden rounded-md border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>Nombre</TableHead>
                        <TableHead className="hidden md:table-cell">Descripción</TableHead>
                        <TableHead className="hidden lg:table-cell">Requerido</TableHead>
                        <TableHead className="hidden lg:table-cell">Vigencia</TableHead>
                        <TableHead>Tipo</TableHead>
                        <TableHead className="hidden sm:table-cell">Fecha de creación</TableHead>
                        <TableHead className="text-right"></TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {entities.map((document) => (
                        <DocumentTableRow
                          key={document.id}
                          entity={document}
                          canEdit={permissions.canEdit}
                          canDelete={permissions.canDelete}
                          isLoading={isLoading}
                          isDeleting={isDeleting}
                          isSaving={isSaving}
                          onEdit={handleEdit}
                          onDelete={handleDeleteClick}
                        />
                      ))}
                    </TableBody>
                  </Table>
                </div>
                {entities.length > 0 && (
                  <Pagination
                    pagination={pagination}
                    onPageChanged={onPageChanged}
                  />
                )}
              </>
            )}
          </section>
        </div>
      </section>

      <DocumentFormModal
        isOpen={modalOpen}
        mode={modalMode}
        entity={selectedEntity ?? undefined}
        onClose={handleModalClose}
        onSuccess={handleModalClose}
      />

      <ConfirmDialog
        isOpen={deleteConfirmOpen}
        variant="delete"
        entityName={entityToDelete?.name}
        isLoading={isDeleting}
        onConfirm={handleDeleteConfirm}
        onCancel={() => {
          setDeleteConfirmOpen(false)
          setEntityToDelete(null)
        }}
      />
    </div>
  )
}
```

**Patrón responsive en tablas:**
- Columnas esenciales siempre visibles
- Columnas secundarias: `className="hidden md:table-cell"` (tablet+)
- Columnas terciarias: `className="hidden lg:table-cell"` (desktop)
- Columnas opcionales: `className="hidden sm:table-cell"` (mobile landscape+)

### 2.8 Ejemplo Completo: Form

```tsx
import { Button } from '@/components/ui/button'
import {
  Field, FieldDescription, FieldError, FieldGroup,
  FieldLabel, FieldLegend, FieldSet,
} from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { documentFormSchema, type DocumentFormValues, type IDocument } from '@/modules/document/schema'
import { DocumentResource } from '@/modules/document/services/DocumentResource'
import { useDocumentStore } from '@/modules/document/store/documentStore'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import type { IFormProps } from '@/shared/interfaces/Entity'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useRef } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface DocumentFormProps extends IFormProps<IDocument> {
  onSubmitRef?: React.MutableRefObject<(() => void) | null>
}

export const DocumentForm = ({ mode, entity, onSuccess, onCancel, formId, onSubmitRef }: DocumentFormProps) => {
  const defaultValues = useMemo<DocumentFormValues>(() => ({
    name: entity?.name ?? '',
    description: entity?.description ?? '',
    type_id: entity?.type_id ?? '',
    required: Boolean(entity?.required ?? false),
    vigency: Boolean(entity?.vigency ?? false),
  }), [entity])

  const crud = useBaseCrud<IDocument, DocumentFormValues>({
    store: useDocumentStore,
    resource: {
      save: DocumentResource.create,
      update: DocumentResource.update,
      delete: DocumentResource.remove,
    },
    mode,
    entityId: entity?.id,
    initialData: entity,
  })

  const {
    register, handleSubmit, reset, control,
    formState: { errors },
  } = useForm<DocumentFormValues>({
    resolver: zodResolver(documentFormSchema),
    defaultValues,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const doSubmit = handleSubmit(async (values) => {
    const result = await crud.submit(values)
    if (result) {
      onSuccess?.(result)
    }
  })

  // Expone el trigger de submit al modal padre via ref
  const doSubmitRef = useRef(doSubmit)
  doSubmitRef.current = doSubmit
  useEffect(() => {
    if (onSubmitRef) {
      onSubmitRef.current = () => void doSubmitRef.current()
    }
  }, [onSubmitRef])

  const { catalogs } = useCatalogs({
    [CatalogType.DOCUMENT_TYPE]: { _sort: 'name' },
  })

  const isSaving = crud.store.isSaving

  return (
    <form id={formId} onSubmit={(e) => { e.preventDefault(); void doSubmit(e) }}>
      <FieldGroup>
        <RequiredFieldsLegend />
        <FieldSet>
          <FieldLegend>Información del documento</FieldLegend>
          <FieldDescription>Datos principales del documento</FieldDescription>
          <FieldGroup>
            {/* Grid responsive: 12 cols, gap-4 */}
            <div className="grid grid-cols-12 gap-4">
              <Field className="col-span-12">
                <FieldLabel htmlFor="document-name" required>Nombre</FieldLabel>
                <Input
                  id="document-name"
                  placeholder="Nombre del documento"
                  {...register('name')}
                  disabled={isSaving}
                />
                <FieldError>{errors.name?.message}</FieldError>
              </Field>

              <Field className="col-span-12 md:col-span-4">
                <FieldLabel htmlFor="document-required">Requerido</FieldLabel>
                <Controller
                  name="required"
                  control={control}
                  render={({ field }) => (
                    <Switch
                      id="document-required"
                      checked={field.value ?? false}
                      onCheckedChange={field.onChange}
                      disabled={isSaving}
                    />
                  )}
                />
              </Field>

              <Field className="col-span-12 md:col-span-4">
                <FieldLabel htmlFor="document-vigency">Vigencia</FieldLabel>
                <Controller
                  name="vigency"
                  control={control}
                  render={({ field }) => (
                    <Switch
                      id="document-vigency"
                      checked={field.value ?? false}
                      onCheckedChange={field.onChange}
                      disabled={isSaving}
                    />
                  )}
                />
              </Field>

              <Field className="col-span-12 md:col-span-4">
                <FieldLabel htmlFor="document-type">Tipo de documento</FieldLabel>
                <Controller
                  name="type_id"
                  control={control}
                  render={({ field }) => (
                    <Select
                      value={field.value || undefined}
                      onValueChange={field.onChange}
                      disabled={isSaving}
                    >
                      <SelectTrigger id="document-type" className="w-full">
                        <SelectValue placeholder="Seleccionar tipo..." />
                      </SelectTrigger>
                      <SelectContent>
                        {catalogs[CatalogType.DOCUMENT_TYPE]?.map((item) => (
                          <SelectItem key={item.id} value={item.id}>
                            {item.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                <FieldError>{errors.type_id?.message}</FieldError>
              </Field>

              <Field className="col-span-12">
                <FieldLabel htmlFor="document-description">Descripción</FieldLabel>
                <Textarea
                  id="document-description"
                  placeholder="Descripción del documento..."
                  className="resize-none"
                  rows={4}
                  {...register('description')}
                  disabled={isSaving}
                />
                <FieldDescription>Detalle brevemente el propósito del documento</FieldDescription>
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>

        {/* Botones solo se muestran si NO hay formId ni onSubmitRef (modo standalone) */}
        {!formId && !onSubmitRef && (
          <Field orientation="horizontal" className="justify-end border-t border-gray-200 pt-6">
            {onCancel && (
              <Button type="button" variant="outline" onClick={onCancel} disabled={isSaving} className="min-w-[80px]">
                Cancelar
              </Button>
            )}
            <Button type="submit" disabled={isSaving} className="min-w-[100px]">
              {isSaving
                ? mode === CrudMode.EDIT ? 'Actualizando...' : 'Creando...'
                : mode === CrudMode.EDIT ? 'Actualizar' : 'Crear'}
            </Button>
          </Field>
        )}
      </FieldGroup>
    </form>
  )
}
```

**Patrón responsive en formularios:**
- Usar `grid grid-cols-12 gap-4`
- Full width en mobile: `col-span-12`
- Dividir en tablet+: `col-span-12 md:col-span-6` o `md:col-span-4`
- Campos de texto largo siempre `col-span-12`

#### Campos requeridos — Convención (obligatorio en todos los formularios internos)

Todo formulario de ingreso de datos interno debe cumplir dos reglas:

1. **`<FieldLabel required>`** en cada campo cuyo schema Zod sea requerido:
   - Requerido: `.min(1)`, `.positive()`, o tipo base sin `.optional()` / `.nullable()`
   - Opcional: `.optional()` / `.nullable()` → omitir `required`
   - El `*` se renderiza automáticamente; nunca escribirlo a mano.

2. **`<RequiredFieldsLegend />`** como primer elemento del cuerpo del formulario (antes del primer `<FieldSet>`):

```tsx
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
```

> **Exenciones:** formularios públicos/auth (`login-form`, `signup-form`, `forgot-password-form`, `reset-password-form`, `accept-invitation-form`). Nunca modificar `components/ui/`.
> Diseño completo: `docs/superpowers/specs/2026-06-30-formularios-datos-requeridos-design.md`.

### 2.9 Ejemplo Completo: FormModal

```tsx
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DocumentForm } from '@/modules/document/DocumentForm'
import type { IDocument } from '@/modules/document/schema'
import { useDocumentStore } from '@/modules/document/store/documentStore'
import { CrudMode } from '@/shared/enums/CrudMode'
import type { IEntityFormModalProps } from '@/shared/interfaces/list.types'
import { useRef } from 'react'

export const DocumentFormModal = ({
  isOpen,
  mode,
  entity,
  onClose,
  onSuccess,
}: IEntityFormModalProps<IDocument>) => {
  const isSaving = useDocumentStore((s) => s.isSaving)
  const submitRef = useRef<(() => void) | null>(null)

  const handleSuccess = (result: IDocument) => {
    onSuccess?.(result)
    onClose()
  }

  if (!isOpen) {
    return null
  }

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="p-0 max-w-4xl" aria-describedby={undefined}>
        <DialogHeader className="shrink-0 px-6 pt-6">
          <DialogTitle>
            {mode === CrudMode.EDIT ? 'Editar documento' : 'Nuevo documento'}
          </DialogTitle>
        </DialogHeader>

        <div className="flex-1 min-h-0 overflow-y-auto px-6 py-2">
          <DocumentForm
            mode={mode}
            entity={entity || undefined}
            onSuccess={handleSuccess}
            onSubmitRef={submitRef}
          />
        </div>

        <DialogFooter className="shrink-0 px-6 pb-6">
          <Button type="button" variant="outline" onClick={onClose} disabled={isSaving} className="min-w-[80px]">
            Cancelar
          </Button>
          <Button
            type="button"
            onClick={() => submitRef.current?.()}
            disabled={isSaving}
            className="min-w-[100px]"
          >
            {isSaving
              ? mode === CrudMode.EDIT ? 'Actualizando...' : 'Creando...'
              : mode === CrudMode.EDIT ? 'Actualizar' : 'Crear'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
```

**Patrón submitRef:**
El Form expone su función `doSubmit` via `onSubmitRef`. El Modal la invoca desde el botón del footer. Esto permite que el Form sea reutilizable tanto en modal como standalone.

### 2.10 Ejemplo Completo: TableRow

```tsx
import { TableCell, TableRow } from '@/components/ui/table'
import type { IDocument } from '@/modules/document/schema'
import { ActionButtonGroup } from '@/shared/components/molecules/ActionButtonGroup'
import type { IEntityTableRowProps } from '@/shared/interfaces/list.types'
import { checkMemoListProps } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { memo } from 'react'

export const DocumentTableRow = memo<IEntityTableRowProps<IDocument>>(({
  entity,
  canEdit,
  canDelete,
  isLoading,
  isDeleting,
  isSaving,
  onEdit,
  onDelete,
}) => {
  return (
    <TableRow key={entity.id}>
      <TableCell>
        <div className="font-medium">{entity.name}</div>
      </TableCell>
      <TableCell className="hidden md:table-cell text-muted-foreground">
        {entity.description || '—'}
      </TableCell>
      <TableCell className="hidden lg:table-cell">
        {entity.required ? 'Sí' : 'No'}
      </TableCell>
      <TableCell className="hidden lg:table-cell">
        {entity.vigency ? 'Sí' : 'No'}
      </TableCell>
      <TableCell>{entity.type?.name || '—'}</TableCell>
      <TableCell className="hidden sm:table-cell">
        {displayFormatDate(entity.created_at)}
      </TableCell>
      <TableCell className="actions">
        <div className="flex items-center justify-end gap-2">
          <ActionButtonGroup
            dropdown={true}
            onEdit={canEdit ? () => onEdit(entity) : undefined}
            edit={canEdit}
            remove={canDelete}
            onRemove={canDelete ? () => onDelete(entity) : undefined}
            disabled={isLoading || isDeleting || isSaving}
          />
        </div>
      </TableCell>
    </TableRow>
  )
}, (prevProps, nextProps) => checkMemoListProps<IDocument>(prevProps, nextProps))

DocumentTableRow.displayName = 'DocumentTableRow'
```

**SIEMPRE:**
- Usar `memo` con `checkMemoListProps` para evitar re-renders.
- Definir `displayName` al usar `memo`.
- Usar `ActionButtonGroup` para acciones.
- Columnas responsive con `hidden md:table-cell`, etc.

---

### 2.11 Catálogos simples: `CatalogCrudModule` (patrón declarativo)

Para un **catálogo simple** —CRUD plano de pocos campos, sin UI custom, sin tabs
ni lógica de negocio en el formulario— NO se crean los 9 archivos de §2.1. Se usa
el genérico `CatalogCrudModule` (`shared/components/catalog/CatalogCrudModule.tsx`),
que ya resuelve lista + búsqueda + paginación + modal de alta/edición + confirm
de borrado + gating de permisos. El módulo queda en **4 archivos** (~70 líneas en
total).

**Regla de decisión — simple (`CatalogCrudModule`) vs completo (§2.2–2.10):**

| Usá `CatalogCrudModule` (§2.11) cuando… | Usá el patrón completo (§2.2–2.10) cuando… |
|---|---|
| CRUD plano: nombre + unos pocos campos (text/number/textarea/switch/color/catalog) | Hay UI custom: tabs, secciones, wizard, vista de detalle |
| El form entra en un grid de 12 columnas sin lógica condicional | El form tiene campos condicionales, cálculos o previews en vivo |
| Las columnas son valores directos o un `render` simple | La fila/tabla necesita acciones especiales o estados complejos |
| Ej.: **tower**, section, unitType, unitStatus, expenseCategory, unitFeature | Ej.: charge/chargeBatch, unit, collaborator, configuration |

> Catálogos ya migrados a este patrón (verificado): `tower`, `section`,
> `unitType`, `unitStatus`, `unitFeature`, `unitRelationship`, `expenseCategory`,
> `vehicle`, `userProfile`, `access`, `catalogs`. El **canónico es `tower`**.

**Estructura (4 archivos):**

```
tower/
├── schema.ts                  # Zod schema + interface + FormValues (igual que §2.2)
├── TowerListPage.tsx          # UN <CatalogCrudModule> declarativo
├── services/TowerResource.ts  # createCrudApi (igual que §2.4)
└── store/towerStore.ts        # createEntityStore (igual que §2.3)
```

No hay `List`, `Form`, `FormModal`, `TableRow` ni `hooks/`: los aporta el genérico.

**Ejemplo canónico — `tower`.** Las 3 capas de datos son idénticas en forma a
§2.2/§2.3/§2.4:

```typescript
// store/towerStore.ts
export const useTowerStore = createEntityStore<ITower>('tower')

// services/TowerResource.ts
export const TowerResource = createCrudApi<ITower, TowerFormValues>({ basePath: '/tower' })

// schema.ts
export const towerFormSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido'),
  floors_count: z.number().int().min(1, 'Mínimo 1 piso').nullable(),
  section_id: z.string().optional(),
  order: z.number().int().nullable(),
})
export interface ITower extends IEntity {
  name: string
  floors_count?: number | null
  section_id?: string | null
  order?: number | null
  section?: { id: string; name: string } | null
}
export type TowerFormValues = z.infer<typeof towerFormSchema>
```

`TowerListPage.tsx` — TODO el módulo de UI es esta única etiqueta declarativa:

```tsx
export const TowerListPage = () => (
  <CatalogCrudModule<ITower, TowerFormValues>
    title="Torres"
    description="Torres o edificios del clúster"
    newLabel="Nueva torre"
    editLabel="Editar torre"
    searchPlaceholder="Buscar torres…"
    permissionEntity="tower"
    store={useTowerStore}
    resource={TowerResource}
    schema={towerFormSchema}
    buildDefaults={(entity) => ({
      name: entity?.name ?? '',
      floors_count: entity?.floors_count ?? null,
      section_id: entity?.section_id ?? undefined,
      order: entity?.order ?? null,
    })}
    fields={[
      { name: 'name', label: 'Nombre', required: true, placeholder: 'Torre A' },
      { name: 'floors_count', label: 'Pisos', type: 'number', colSpan: 6 },
      { name: 'order', label: 'Orden', type: 'number', colSpan: 6 },
      { name: 'section_id', label: 'Sección', type: 'catalog', catalog: CatalogType.SECTION },
    ]}
    columns={[
      { key: 'floors_count', label: 'Pisos' },
      { key: 'section', label: 'Sección', render: (t) => t.section?.name ?? '—' },
    ]}
    initialSearch={{
      sort: [{ field: 'order', direction: 'asc' }],
      includes: [{ relation: 'section' }],
    }}
  />
)
```

**API de props de `CatalogCrudModule<T, V>`:**

| Prop | Tipo | Qué hace |
|---|---|---|
| `title` / `description` | `string` | Encabezado de la página |
| `newLabel` / `editLabel` | `string` | Texto del botón crear / título del modal en edición |
| `searchPlaceholder` | `string` | Placeholder del buscador |
| `permissionEntity` | `string` | Entidad de permisos (`verb.entity`), ej. `'tower'` → gatea crear/editar/borrar |
| `store` | `BoundEntityStore<T>` | El `createEntityStore` del módulo |
| `resource` | `{ list, search, create, update, remove }` | El `createCrudApi` del módulo |
| `schema` | `z.ZodType<V, V>` | El Zod schema del form |
| `fields` | `CatalogFieldConfig[]` | Campos del form (ver abajo) |
| `columns?` | `CatalogColumn<T>[]` | Columnas extra entre Nombre y Fecha de creación |
| `buildDefaults` | `(entity?) => V` | Valores por defecto del form (alta y edición) |
| `initialSearch?` | `ISearchParams` | `sort` / `includes` iniciales |
| `nameLabel?` | `string` | Encabezado de la 1ª columna (default `"Nombre"`) |
| `renderName?` | `(entity) => string` | Render de la 1ª columna y del nombre en el confirm de borrado (default `entity.name`) |

**`CatalogFieldConfig` (cada campo del form):**

```typescript
{
  name: string                  // key del schema
  label: string                 // en español
  type?: 'text' | 'number' | 'textarea' | 'switch' | 'color' | 'catalog'  // default 'text'
  catalog?: CatalogType         // requerido si type === 'catalog'
  placeholder?: string
  required?: boolean
  colSpan?: 4 | 6 | 12          // ancho en el grid de 12 (default 12)
}
```

**Reglas:**
- La primera columna (el `name`) la renderiza el genérico; NO la repitas en `columns`.
- `type: 'catalog'` resuelve el `<Select>` vía `useCatalogs` con el `CatalogType`
  indicado — no cargues el catálogo a mano.
- Mensajes/labels/placeholders SIEMPRE en español (igual que todo el front).
- **Límite del patrón:** si el form empieza a necesitar lógica condicional,
  cálculos, previews o un layout que no entra en `fields[]`, ESO ya dejó de ser un
  catálogo simple → migrá al patrón completo (§2.2–2.10). NO fuerces
  `CatalogCrudModule` con hacks.

---

## 2.12 Carga LAZY de catálogos (ahorro de infraestructura — HARD)

> Objetivo de negocio: minimizar el costo de backend. Los catálogos (`POST /selects`)
> deben cargar lo MÁS TARDE posible — **NUNCA al montar una lista**. Auditado por
> `@infra-cost-reviewer`.

**Por qué se filtra:** `useCatalogs` dispara `/selects` apenas montea el componente que
lo llama (su único gate nativo es que el request no esté vacío). El límite de montaje
real es el `<Sheet>`/`<Dialog>` de Radix: SIN `forceMount`, Radix DESMONTA los hijos de
`<SheetContent>` mientras está cerrado. Por eso un `useCatalogs`:
- dentro de `<SheetContent>` (o en un hijo montado SÓLO al abrir) = LAZY ✓
- en el cuerpo de un modal SIEMPRE montado (`<XFormModal isOpen={open}/>` — `isOpen`
  sólo togglea la visibilidad Radix, NO el montaje React), arriba del `<Sheet>` = EAGER ✗

### Catálogos de FORMULARIO → cargar al abrir el form
Patrón oro: `DocumentFormModal` (§2.9) — el modal hace `if (!isOpen) return null` y el
`useCatalogs` vive en el `<Form>` hijo que sólo montea al abrir. Tres variantes válidas:
1. **Extraer hijo (oro):** el modal es un wrapper hook-free con `if (!isOpen) return null`
   que delega cuerpo + `useCatalogs` a un hijo (`<XFormSheet>`/`<XFormBody>`).
2. **Gate `enabled`:** `useCatalogs(req, { enabled: isOpen })` — no dispara mientras
   `isOpen` sea false. Útil cuando el modal ya tiene muchos hooks y extraer sería caro.
3. **Montaje condicional:** la lista renderiza `{open && <XFormModal isOpen={open} .../>}`.

> ⚠️ **Regla de hooks:** un `if (!isOpen) return null` NO puede ir DESPUÉS de un
> `useCatalogs` en el MISMO componente. Si el hook está en ese componente → usá el gate
> `enabled` (variante 2) o extraé un hijo (variante 1). Nunca un early-return antes de
> los hooks del propio componente (ESLint `react-hooks/rules-of-hooks` lo bloquea).

### Catálogos de FILTRO → cargar al abrir el dropdown
Usá **`useLazyCatalogs(req)`** (variante lazy de `useCatalogs`, mismo retorno +
`enable()`/`isEnabled`): no dispara hasta llamar `enable()`. Cableá `enable()` a la
apertura del dropdown:
```tsx
const unit = useLazyCatalogs(useMemo(() => ({ [CatalogType.UNIT]: { _sort: 'name' } }), []))
// Select nativo de shadcn:
<Select onOpenChange={(open) => { if (open) unit.enable() }}> … </Select>
// MultiSelectFilter compartido (tiene prop dedicada onFirstOpen):
<MultiSelectFilter … onFirstOpen={unit.enable} />
```
- **Granularidad (máximo ahorro):** cada filtro pesado/independiente (UNIT, USER) con su
  PROPIO `useLazyCatalogs`, así abrir un filtro carga sólo ESE catálogo. Catálogos chicos
  que se abren juntos pueden compartir uno.
- **Memoizá el request** (`useMemo(() => ({...}), [])`) para no re-mapear opciones en cada
  render (el `queryKey` de React Query igual se hashea por contenido, pero el memo de
  opciones aguas abajo sí depende de la referencia).
- **Excepción deep-link:** si una lista puede montarse con un filtro pre-aplicado que
  necesita su catálogo para pintar el label activo, eager-loadeá SÓLO ese catálogo. Los
  `MultiSelectFilter` en modo `compact` muestran un conteo y NO necesitan el catálogo para
  el label — ahí no hace falta excepción.

### Anti-patrón (NUNCA)
`useCatalogs` al scope de una `List`/página, o en el cuerpo de un modal SIEMPRE montado,
para datos que sólo usa el form/dropdown. Un `if (!isOpen) return` DENTRO de un custom
hook NO frena el fetch (no es un guard de montaje del componente).

---

## 2.13 `<SearchSelect>` — el select unificado con búsqueda (estilo ng-select)

> Componente ÚNICO para todos los dropdowns con búsqueda. Reemplaza el uso directo del
> `<Select>` shadcn cuando la lista conviene filtrarse. Construido sobre `react-select` v5
> (`unstyled` + `classNames`) para verse 100% shadcn. Ubicación:
> `shared/components/SearchSelect.tsx`.

**Habla en IDs por fuera** (igual que el `<Select>` shadcn: `value` = id/ids, `onChange` =
id/ids), así es drop-in con `<Controller>` de RHF. Búsqueda en CLIENTE por defecto; remota con
`loadOptions`.

```tsx
// Catálogo / lista ya cargada → search en cliente:
<SearchSelect<ICatalogItem>
  value={field.value}
  onChange={(v) => field.onChange((v as string | null) ?? '')}
  options={catalogs[CatalogType.UNIT]}
  placeholder="Buscar unidad…"
/>

// Lista grande / remota → typeahead con debounce (MISMO componente):
<SearchSelect<IUser>
  value={field.value}
  onChange={field.onChange}
  loadOptions={(term) => UserResource.searchByTerm(term)}
  selectedOptions={current ? [current] : undefined}  // pinta el label en edición
  multiple
/>
```

Props clave: `value`, `onChange`, `options` (cliente) | `loadOptions` (remoto), `selectedOptions`
(label del valor actual en modo remoto), `multiple`, `isClearable` (def. false — opt-in en campos opcionales), `isLoading`,
`isDisabled`, `invalid`, `placeholder`, `getOptionLabel`/`getOptionValue` (def. `name`/`id`),
`menuPortal` (def. true — evita clipping dentro de Sheets/Dialogs).

- **Cuándo usarlo:** cualquier select cuya lista convenga filtrar (catálogos medianos+, usuarios,
  unidades, conceptos…). Para 3-5 opciones fijas, el `<Select>` shadcn simple sigue bien.
- **Compone con lazy (§2.12):** en filtros, alimentá `options` desde `useLazyCatalogs` y disparás
  `enable()` al abrir; en remoto el typeahead ni siquiera carga el catálogo entero.
- **`onChange` devuelve `string | string[] | null`** — en single coerce con `(v as string|null) ?? ''`
  si tu consumer espera `string`.
- NO re-estilar `react-select` a mano en cada módulo: usá SIEMPRE este wrapper (consistencia — el
  dolor que lo motivó fue "todos los selects son distintos").

## 3. COMPONENTES SHARED CLAVE (NO MODIFICAR, SOLO REUTILIZAR)

### CatalogCrudModule
Módulo CRUD declarativo para **catálogos simples** (ver §2.11). Una sola etiqueta
`<CatalogCrudModule>` genera lista + búsqueda + paginación + modal de
alta/edición + borrado + permisos a partir de props (`fields`, `columns`,
`buildDefaults`, `initialSearch`). Para catálogos grandes o con UI custom NO se
usa: ir al patrón completo (§2.2–2.10). Canónico: `modules/tower`.

### Pagination
```tsx
<Pagination pagination={pagination} onPageChanged={onPageChanged} />
```
Ya maneja: primera/anterior/siguiente/última página, selector de tamaño (20, 50, 100), conteo total.

### SearchList
```tsx
<SearchList
  paramsSearch={paramsSearch}
  onSearch={onSearch}
  onReset={onReset}
  placeholder="Buscar documentos..."
/>
```
Ya maneja: barra de búsqueda, botón buscar, botón reset. Soporta `children` para filtros adicionales y `collapsibleFilters` para ocultar/mostrar filtros.

### ConfirmDialog
```tsx
<ConfirmDialog
  isOpen={deleteConfirmOpen}
  variant="delete"
  entityName={entityToDelete?.name}
  isLoading={isDeleting}
  onConfirm={handleDeleteConfirm}
  onCancel={() => setDeleteConfirmOpen(false)}
/>
```

### PermissionGuard
```tsx
<PermissionGuard permission="create.document">
  <Button>Nuevo documento</Button>
</PermissionGuard>
```

### useMultiplePermissions
```tsx
const permissions = useMultiplePermissions({
  canCreate: 'create.document',
  canEdit: 'update.document',
  canDelete: 'delete.document',
})
// Uso: permissions.canCreate, permissions.canEdit, etc.
```

### useCatalogs / useLazyCatalogs
```tsx
const { catalogs } = useCatalogs({
  [CatalogType.DOCUMENT_TYPE]: { _sort: 'name' },
  [CatalogType.AREA]: { _sort: 'name' },
})
// Uso: catalogs[CatalogType.DOCUMENT_TYPE]?.map(...)
```
- `useCatalogs(req, { enabled })` — gate opcional para diferir la carga (default `true`).
- `useLazyCatalogs(req)` — NO dispara `/selects` hasta llamar `enable()`. Para FILTROS.
> ⚠️ **Carga LAZY obligatoria** (ahorro de infra): los catálogos NO deben cargar al montar
> una lista. Ver **§2.12** (form → load-on-form-open; filtro → load-on-dropdown-open).

### PageHeader
```tsx
<PageHeader title="Documentos" description="Gestiona los documentos del sistema" />
```

### TableLoadingState / TableEmptyState
```tsx
{isLoading ? <TableLoadingState /> : entities.length === 0 ? <TableEmptyState /> : (/* tabla */)}
```

### ActionButtonGroup
```tsx
<ActionButtonGroup
  dropdown={true}
  edit={canEdit}
  remove={canDelete}
  onEdit={canEdit ? () => onEdit(entity) : undefined}
  onRemove={canDelete ? () => onDelete(entity) : undefined}
  disabled={isLoading || isDeleting || isSaving}
/>
```

### displayFormatDate
```tsx
import { displayFormatDate } from '@/shared/utils/dateHelper'
// Uso: displayFormatDate(entity.created_at)
```

---

## 4. REGLAS ESTRICTAS FRONTEND

### NUNCA hacer:

1. **NUNCA crear archivos barrel** (`index.ts`, `index.tsx`). Cada archivo se importa directamente por su ruta.

2. **NUNCA usar `any`** en TypeScript. Siempre definir interfaces o tipos concretos.

3. **NUNCA usar ternarios anidados.** Si necesitas más de un nivel, usa `if/else` o variables intermedias.

4. **UI siempre en español:** Todos los textos, placeholders, mensajes de error, toasts, labels.

5. **NUNCA crear componentes/lógica duplicada.** Revisa primero:
   - `shared/hooks/` → useBaseList, useBaseCrud, useCatalogs, usePermissions
   - `shared/components/` → Pagination, SearchList, ConfirmDialog, Modal, PermissionGuard
   - `shared/store/EntityStore.ts` → createEntityStore
   - `shared/api/resourceApi.ts` → createCrudApi
   - `components/ui/` → Todos los átomos UI (button, input, select, dialog, table, etc.)

6. **Responsive obligatorio.** Toda UI debe funcionar en:
   - Mobile (< 640px): `col-span-12`, `hidden sm:...`
   - Tablet (640-1024px): `sm:col-span-6`, `md:col-span-4`
   - Desktop (> 1024px): `lg:col-span-3`, `xl:...`

7. **NUNCA modificar componentes de `components/ui/`** (shadcn). Son generados y se mantienen como están.

8. **NUNCA hacer imports con `type` de otros módulos para relaciones simples.** Usa objetos inline en la interface.

---

## 5. GUÍA RÁPIDA: CREAR UN MÓDULO FRONTEND

**Primero decidí (§2.11): ¿catálogo simple o entidad completa?**

### Opción A — Catálogo simple (4 archivos) — RECOMENDADO para catálogos, ver §2.11

1. `schema.ts` — Zod schema + interface + FormValues type
2. `store/{moduleName}Store.ts` — `createEntityStore<IEntity>('name')`
3. `services/{ModuleName}Resource.ts` — `createCrudApi<IEntity, FormValues>({ basePath })`
4. `{ModuleName}ListPage.tsx` — un `<CatalogCrudModule>` declarativo (`fields`, `columns`, `buildDefaults`, `initialSearch`)

### Opción B — Entidad completa / con UI custom (9 archivos), ver §2.2–2.10

1. `schema.ts` — Zod schema + interface + FormValues type
2. `store/{moduleName}Store.ts` — `createEntityStore<IEntity>('name')`
3. `services/{ModuleName}Resource.ts` — `createCrudApi<IEntity, FormValues>({ basePath })`
4. `hooks/use{ModuleName}List.ts` — `useBaseList<IEntity>({ store, resource, initialSearch })`
5. `{ModuleName}ListPage.tsx` — Page wrapper
6. `{ModuleName}List.tsx` — Lista completa
7. `{ModuleName}Form.tsx` — Formulario
8. `{ModuleName}FormModal.tsx` — Modal wrapper
9. `components/{ModuleName}TableRow.tsx` — Fila memoizada

Luego:
- Agregar ruta en `App.tsx`
- Agregar item en sidebar (`app-sidebar.tsx`)
- Agregar permisos en el backend (seeders/migrations de permisos)
