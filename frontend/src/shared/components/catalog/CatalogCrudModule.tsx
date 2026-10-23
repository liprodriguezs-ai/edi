import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Modal } from '@/shared/components/Modal'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { CrudMode } from '@/shared/enums/CrudMode'
import type { BoundEntityStore } from '@/shared/hooks/useBaseCrud'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { useBaseList } from '@/shared/hooks/useBaseList'
import type { CatalogType, ICatalogItem } from '@/shared/hooks/useCatalogs'
import { useCatalogs } from '@/shared/hooks/useCatalogs'
import { catalogSortField } from '@/shared/components/catalog/catalogSort'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import type { IEntity } from '@/shared/interfaces/Entity'
import type { ISearchParams } from '@/shared/interfaces/list.types'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { zodResolver } from '@hookform/resolvers/zod'
import { Download, Lock, Pencil, Plus, Trash2 } from 'lucide-react'
import React, { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import {
  Controller,
  useForm,
  type DefaultValues,
  type FieldValues,
  type Path,
} from 'react-hook-form'
import { useShallow } from 'zustand/react/shallow'
import type { z } from 'zod'

export interface CatalogColumn<T extends IEntity> {
  key: string
  label: string
  className?: string
  render?: (_entity: T) => ReactNode
}

export interface CatalogFieldConfig {
  name: string
  label: string
  type?: 'text' | 'number' | 'textarea' | 'switch' | 'color' | 'catalog'
  catalog?: CatalogType
  placeholder?: string
  required?: boolean
  /** Columnas que ocupa en el grid de 12 (default 12) */
  colSpan?: 4 | 6 | 12
}

export interface CatalogResource<T extends IEntity, V extends FieldValues> {
  list: (_params: Record<string, unknown>) => Promise<{ data: T[] }>
  search: (_params: Record<string, unknown>) => Promise<{ data: T[] }>
  create: (_data: V | FormData) => Promise<T>
  update: (_id: string, _data: V | FormData) => Promise<T>
  remove: (_id: string) => Promise<unknown>
}

export interface CatalogCrudModuleProps<T extends IEntity, V extends FieldValues> {
  /** Título de la página, ej. "Torres" */
  title: string
  description: string
  /** Etiqueta del botón crear, ej. "Nueva torre" */
  newLabel: string
  editLabel: string
  searchPlaceholder: string
  /** Entidad de permisos (verb.entity), ej. 'tower' */
  permissionEntity: string
  store: BoundEntityStore<T>
  resource: CatalogResource<T, V>
  schema: z.ZodType<V, V>
  fields: CatalogFieldConfig[]
  /** Columnas extra entre Nombre y Fecha de creación */
  columns?: CatalogColumn<T>[]
  buildDefaults: (_entity?: T | null) => V
  initialSearch?: ISearchParams
  /** Encabezado de la primera columna (default "Nombre") */
  nameLabel?: string
  /** Render de la primera columna y del nombre en el confirm de borrado (default entity.name) */
  renderName?: (_entity: T) => string
  /**
   * Optional export: renders a Descargar button gated by `report.${permissionEntity}`.
   * Set both to enable server-side xlsx export via `onReport(reportModel, 'xlsx', reportTitle)`.
   */
  reportModel?: string
  reportTitle?: string
}

export interface CatalogFormProps<T extends IEntity, V extends FieldValues> {
  config: CatalogCrudModuleProps<T, V>
  mode: CrudMode
  entity: T | null
  isOpen: boolean
  onClose: () => void
}

export function CatalogFormModal<T extends IEntity & { name?: string }, V extends FieldValues>(
  props: CatalogFormProps<T, V>
) {
  // Gate del montaje real: sin esto el useCatalogs del cuerpo dispararía POST /selects
  // al montar la lista (el panel solo togglea visibilidad de Radix, NO desmonta el
  // contenido). Como este wrapper no usa hooks antes del early-return, respeta las
  // Rules of Hooks y los catálogos solo se piden cuando el form se abre.
  if (!props.isOpen) {
    return null
  }
  return <CatalogFormSheet {...props} />
}

function CatalogFormSheet<T extends IEntity & { name?: string }, V extends FieldValues>({
  config,
  mode,
  entity,
  isOpen,
  onClose,
}: CatalogFormProps<T, V>) {
  const { store, resource, schema, fields, buildDefaults, newLabel, editLabel } = config
  const isSaving = store((s) => s.isSaving)

  const crud = useBaseCrud<T, V>({
    store,
    resource: {
      save: resource.create,
      update: resource.update,
      delete: resource.remove,
    },
    mode,
    entityId: entity?.id,
    initialData: entity,
  })

  const defaultValues = useMemo(() => buildDefaults(entity), [buildDefaults, entity])

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors },
  } = useForm<V>({
    resolver: zodResolver(schema),
    defaultValues: defaultValues as DefaultValues<V>,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const catalogRequest = useMemo(() => {
    const request: Partial<Record<CatalogType, { _sort: string }>> = {}
    for (const field of fields) {
      if (field.type === 'catalog' && field.catalog) {
        request[field.catalog] = { _sort: catalogSortField(field.catalog) }
      }
    }
    return request
  }, [fields])
  const { catalogs, isLoading: isLoadingCatalogs } = useCatalogs(catalogRequest)

  const hasRequired = fields.some((f) => f.required)

  const doSubmit = handleSubmit(async (values) => {
    const result = await crud.submit(values as V)
    if (result) {
      onClose()
    }
  })

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={mode === CrudMode.EDIT ? editLabel : newLabel}
      size='lg'
      footer={
        <>
          <Button
            type='button'
            variant='outline'
            onClick={onClose}
            disabled={isSaving}
            className='min-w-[80px]'
          >
            Cancelar
          </Button>
          <Button
            type='button'
            onClick={() => void doSubmit()}
            loading={isSaving}
            className='min-w-[100px]'
          >
            {isSaving
              ? mode === CrudMode.EDIT
                ? 'Actualizando…'
                : 'Creando…'
              : mode === CrudMode.EDIT
                ? 'Actualizar'
                : 'Crear'}
          </Button>
        </>
      }
    >
      <form
        onSubmit={(e) => {
          e.preventDefault()
          void doSubmit(e)
        }}
      >
        {hasRequired && <RequiredFieldsLegend />}
        <FieldGroup>
          <div className='grid grid-cols-12 gap-4'>
            {fields.map((field) => {
              const fieldName = field.name as Path<V>
              const error = (errors as Record<string, { message?: string } | undefined>)[
                field.name
              ]
              const span = field.colSpan ?? 12
              const spanClass = span === 4 ? 'md:col-span-4' : span === 6 ? 'md:col-span-6' : ''
              const inputId = `catalog-${field.name}`

              return (
                <Field key={field.name} className={`col-span-12 ${spanClass}`}>
                  <FieldLabel htmlFor={inputId} required={field.required}>
                    {field.label}
                  </FieldLabel>
                  {field.type === 'switch' ? (
                    <Controller
                      name={fieldName}
                      control={control}
                      render={({ field: rhf }) => (
                        <Switch
                          id={inputId}
                          checked={Boolean(rhf.value)}
                          onCheckedChange={rhf.onChange}
                          disabled={isSaving}
                        />
                      )}
                    />
                  ) : field.type === 'catalog' ? (
                    <Controller
                      name={fieldName}
                      control={control}
                      render={({ field: rhf }) => (
                        <SearchSelect<ICatalogItem>
                          id={inputId}
                          value={(rhf.value as string) ?? null}
                          onChange={(v) => rhf.onChange((v as string | null) ?? '')}
                          options={field.catalog ? (catalogs[field.catalog] ?? []) : []}
                          isDisabled={isSaving}
                          isLoading={isLoadingCatalogs}
                          invalid={Boolean(error)}
                          placeholder={field.placeholder ?? 'Seleccionar…'}
                          getOptionLabel={(option) =>
                            option.name ||
                            (option as ICatalogItem & { full_identifier?: string })
                              .full_identifier ||
                            ''
                          }
                        />
                      )}
                    />
                  ) : field.type === 'textarea' ? (
                    <Textarea
                      id={inputId}
                      rows={3}
                      className='resize-none'
                      placeholder={field.placeholder}
                      disabled={isSaving}
                      {...register(fieldName)}
                    />
                  ) : field.type === 'color' ? (
                    <Input
                      id={inputId}
                      type='color'
                      className='h-10 w-20 cursor-pointer p-1'
                      disabled={isSaving}
                      {...register(fieldName)}
                    />
                  ) : (
                    <Input
                      id={inputId}
                      type={field.type === 'number' ? 'number' : 'text'}
                      placeholder={field.placeholder}
                      disabled={isSaving}
                      {...register(
                        fieldName,
                        field.type === 'number'
                          ? {
                              setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
                            }
                          : undefined
                      )}
                    />
                  )}
                  <FieldError>{error?.message}</FieldError>
                </Field>
              )
            })}
          </div>
        </FieldGroup>
      </form>
    </Modal>
  )
}

/**
 * CRUD genérico para catálogos (tablas name/order/is_active y similares).
 * Listado + búsqueda + paginación + modal de alta/edición + borrado con confirmación.
 * Los botones de escritura se ocultan solos si el usuario no tiene el permiso
 * (los catálogos centrales son read-only para roles de la organización).
 */
export function CatalogCrudModule<T extends IEntity & { name?: string }, V extends FieldValues>(
  config: CatalogCrudModuleProps<T, V>
) {
  const {
    title,
    description,
    newLabel,
    searchPlaceholder,
    permissionEntity,
    store,
    resource,
    columns = [],
    nameLabel = 'Nombre',
    reportModel,
    reportTitle,
  } = config
  const getName = config.renderName ?? ((entity: T) => entity.name ?? '')

  const { onPageChanged, onRemove, onSearch, onReset, onReport, isExporting, paramsSearch } =
    useBaseList<T>({
      store: store as () => never,
      resource,
      autoInit: true,
      initialSearch: config.initialSearch ?? {
        sort: [{ field: 'name', direction: 'asc' }],
      },
    })

  const { entities, pagination, isLoading } = store(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const isDeleting = store((s) => s.isDeleting)

  const permissionKeys = useMemo(
    () => ({
      canCreate: `create.${permissionEntity}`,
      canEdit: `update.${permissionEntity}`,
      canDelete: `delete.${permissionEntity}`,
    }),
    [permissionEntity]
  )
  const permissions = useMultiplePermissions(permissionKeys)

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selectedEntity, setSelectedEntity] = useState<T | null>(null)
  const [entityToDelete, setEntityToDelete] = useState<T | null>(null)

  const handleCreate = useCallback(() => {
    setSelectedEntity(null)
    setModalMode(CrudMode.CREATE)
    setModalOpen(true)
  }, [])

  const handleEdit = useCallback((entity: T) => {
    setSelectedEntity(entity)
    setModalMode(CrudMode.EDIT)
    setModalOpen(true)
  }, [])

  const handleDeleteConfirm = useCallback(async () => {
    if (!entityToDelete) return
    try {
      await onRemove(entityToDelete)
      setEntityToDelete(null)
    } catch {
      // el toast global ya reporta el error
    }
  }, [entityToDelete, onRemove])

  const handleModalClose = useCallback(() => {
    setModalOpen(false)
    setSelectedEntity(null)
  }, [])

  const showActions = permissions.canEdit || permissions.canDelete

  return (
    <div className='space-y-6'>
      <PageHeader
        title={title}
        description={description}
        actions={
          <>
            {reportModel && (
              <PermissionGuard permission={`report.${permissionEntity}`}>
                <Button
                  variant='outline'
                  onClick={() =>
                    onReport(reportModel, 'xlsx', reportTitle ?? reportModel.toLowerCase())
                  }
                  loading={isExporting}
                  className='flex items-center gap-2'
                >
                  {!isExporting && <Download className='h-4 w-4' />}
                  Descargar
                </Button>
              </PermissionGuard>
            )}
            <PermissionGuard permission={`create.${permissionEntity}`}>
              <Button onClick={handleCreate} className='flex items-center gap-2'>
                <Plus className='h-4 w-4' />
                {newLabel}
              </Button>
            </PermissionGuard>
          </>
        }
      />

      <section className='space-y-6'>
        <SearchList
          paramsSearch={paramsSearch}
          onSearch={onSearch}
          onReset={onReset}
          placeholder={searchPlaceholder}
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState />
        ) : (
          <>
            <ResponsiveDataTable<T>
              rows={entities}
              breakpoint='lg'
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>{nameLabel}</TableHead>
                    {columns.map((col) => (
                      <TableHead scope='col' key={col.key} className={col.className}>
                        {col.label}
                      </TableHead>
                    ))}
                    <TableHead scope='col' className='hidden md:table-cell'>
                      Fecha de creación
                    </TableHead>
                    {showActions && <TableHead scope='col' className='text-right'></TableHead>}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(entity) => (
                <TableRow key={entity.id}>
                  <TableCell className='font-medium'>
                    <span className='inline-flex items-center gap-1.5'>
                      {getName(entity)}
                      {(entity as { is_system?: boolean }).is_system && (
                        <Lock
                          className='size-3.5 text-[var(--ink-4)]'
                          aria-label='Registro de sistema: usado por flujos automáticos, no puede eliminarse'
                        />
                      )}
                    </span>
                  </TableCell>
                  {columns.map((col) => (
                    <TableCell key={col.key} className={col.className}>
                      {col.render
                        ? col.render(entity)
                        : String((entity as Record<string, unknown>)[col.key] ?? '—')}
                    </TableCell>
                  ))}
                  <TableCell className='hidden md:table-cell'>
                    {displayFormatDate(entity.created_at)}
                  </TableCell>
                  {showActions && (
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      <div className='flex justify-end gap-1'>
                        {permissions.canEdit && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8'
                            onClick={() => handleEdit(entity)}
                            aria-label={`Editar ${getName(entity)}`}
                          >
                            <Pencil className='size-4' />
                          </Button>
                        )}
                        {permissions.canDelete &&
                          !(entity as { is_system?: boolean }).is_system && (
                            <Button
                              variant='ghost'
                              size='icon'
                              className='size-8 text-muted-foreground hover:text-destructive'
                              onClick={() => setEntityToDelete(entity)}
                              aria-label={`Eliminar ${getName(entity)}`}
                            >
                              <Trash2 className='size-4' />
                            </Button>
                          )}
                      </div>
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(entity) => getName(entity)}
              renderCardFields={(entity) => [
                ...columns.map((col) => ({
                  label: col.label,
                  value: col.render
                    ? col.render(entity)
                    : (String(
                        (entity as Record<string, unknown>)[col.key] ?? '—'
                      ) as React.ReactNode),
                })),
                { label: 'Fecha de creación', value: displayFormatDate(entity.created_at) },
              ]}
              renderActions={
                showActions
                  ? (entity) => (
                      <div className='flex justify-end gap-1'>
                        {permissions.canEdit && (
                          <Button
                            variant='ghost'
                            size='icon'
                            className='size-8'
                            onClick={() => handleEdit(entity)}
                            aria-label={`Editar ${getName(entity)}`}
                          >
                            <Pencil className='size-4' />
                          </Button>
                        )}
                        {permissions.canDelete &&
                          !(entity as { is_system?: boolean }).is_system && (
                            <Button
                              variant='ghost'
                              size='icon'
                              className='size-8 text-muted-foreground hover:text-destructive'
                              onClick={() => setEntityToDelete(entity)}
                              aria-label={`Eliminar ${getName(entity)}`}
                            >
                              <Trash2 className='size-4' />
                            </Button>
                          )}
                      </div>
                    )
                  : undefined
              }
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <CatalogFormModal
        config={config}
        mode={modalMode}
        entity={selectedEntity}
        isOpen={modalOpen}
        onClose={handleModalClose}
      />

      <ConfirmDialog
        isOpen={Boolean(entityToDelete)}
        variant='delete'
        entityName={entityToDelete ? getName(entityToDelete) : undefined}
        isLoading={isDeleting}
        onConfirm={handleDeleteConfirm}
        onCancel={() => setEntityToDelete(null)}
      />
    </div>
  )
}
