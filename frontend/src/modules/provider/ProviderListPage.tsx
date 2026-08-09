import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { Switch } from '@/components/ui/switch'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { Textarea } from '@/components/ui/textarea'
import {
  providerFormSchema,
  type IProvider,
  type ProviderFormValues,
} from '@/modules/provider/schema'
import { ProviderResource } from '@/modules/provider/services/ProviderResource'
import { useProviderStore } from '@/modules/provider/store/providerStore'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { usePermissions } from '@/shared/hooks/usePermissions'
import {
  displayMoney,
  minorUnitsToPesos,
  moneyToMinorUnits,
  pesosToMinorUnits,
} from '@/shared/utils/money'
import { zodResolver } from '@hookform/resolvers/zod'
import { Download, Loader2, Pencil, Plus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { useShallow } from 'zustand/react/shallow'

interface ProviderModalProps {
  isOpen: boolean
  mode: CrudMode
  entity: IProvider | null
  onClose: () => void
}

function str(value: string | null | undefined): string {
  return value ?? ''
}

function providerEstimatedAmount(entity: IProvider | null): number | null {
  if (entity?.estimated_amount == null) return null
  return minorUnitsToPesos(moneyToMinorUnits(entity.estimated_amount))
}

function providerContactFields(entity: IProvider | null) {
  return {
    contact_person: str(entity?.contact_person),
    phone: str(entity?.phone),
    email: str(entity?.email),
    address: str(entity?.address),
  }
}

function providerBankingFields(entity: IProvider | null) {
  return {
    clabe: str(entity?.clabe),
    bank: str(entity?.bank),
    usual_payment_day: entity?.usual_payment_day ?? null,
  }
}

function buildProviderDefaultValues(entity: IProvider | null): ProviderFormValues {
  return {
    name: str(entity?.name),
    provider_type_id: str(entity?.provider_type_id),
    provider_service_type_id: str(entity?.provider_service_type_id),
    rfc: str(entity?.rfc),
    ...providerContactFields(entity),
    is_recurring: entity?.is_recurring ?? false,
    estimated_amount: providerEstimatedAmount(entity),
    ...providerBankingFields(entity),
    notes: str(entity?.notes),
    is_active: entity?.is_active ?? true,
  }
}

function ProviderFormModal(props: ProviderModalProps) {
  if (!props.isOpen) return null
  return <ProviderFormSheet {...props} />
}

function ProviderFormSheet({ isOpen, mode, entity, onClose }: ProviderModalProps) {
  const isSaving = useProviderStore((s) => s.isSaving)

  const crud = useBaseCrud<IProvider, Record<string, unknown>>({
    store: useProviderStore,
    resource: {
      save: ProviderResource.create,
      update: ProviderResource.update,
    },
    mode,
    entityId: entity?.id,
    initialData: entity,
  })

  const defaultValues = useMemo<ProviderFormValues>(
    () => buildProviderDefaultValues(entity),
    [entity]
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    watch,
    formState: { errors },
  } = useForm<ProviderFormValues>({
    resolver: zodResolver(providerFormSchema),
    defaultValues,
  })

  useEffect(() => {
    if (isOpen) reset(defaultValues)
  }, [isOpen, reset, defaultValues])

  const { catalogs } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.PROVIDER_TYPE]: { _sort: 'order' },
        [CatalogType.PROVIDER_SERVICE_TYPE]: { _sort: 'order' },
      }),
      []
    )
  )

  const isRecurring = watch('is_recurring')

  const doSubmit = handleSubmit(async (values) => {
    const payload: Record<string, unknown> = {
      ...values,
      email: values.email || null,
      estimated_amount:
        values.estimated_amount != null ? pesosToMinorUnits(values.estimated_amount) : null,
      frequency: values.is_recurring ? 'monthly' : null,
    }
    const result = await crud.submit(payload)
    if (result) onClose()
  })

  const catalogSelect = (
    name: 'provider_type_id' | 'provider_service_type_id',
    label: string,
    catalog: CatalogType
  ) => (
    <Field className='col-span-12 md:col-span-6'>
      <FieldLabel htmlFor={`provider-${name}`} required>
        {label}
      </FieldLabel>
      <Controller
        name={name}
        control={control}
        render={({ field }) => (
          <SearchSelect
            id={`provider-${name}`}
            value={field.value}
            onChange={(v) => field.onChange((v as string | null) ?? '')}
            options={catalogs[catalog] ?? []}
            isDisabled={isSaving}
            invalid={!!errors[name]}
            placeholder='Seleccionar…'
          />
        )}
      />
      <FieldError>{errors[name]?.message}</FieldError>
    </Field>
  )

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side='right'
        className='flex flex-col gap-0 p-0 w-full sm:max-w-2xl'
        aria-describedby={undefined}
      >
        <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
          <SheetTitle>{mode === CrudMode.EDIT ? 'Editar proveedor' : 'Nuevo proveedor'}</SheetTitle>
        </SheetHeader>

        <form
          className='min-h-0 flex-1 overflow-y-auto px-6 py-2'
          onSubmit={(e) => {
            e.preventDefault()
            void doSubmit(e)
          }}
        >
          <FieldGroup>
            <RequiredFieldsLegend />
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-12 md:col-span-8'>
                <FieldLabel htmlFor='provider-name' required>
                  Nombre / Razón social
                </FieldLabel>
                <Input
                  id='provider-name'
                  placeholder='Jardinería López'
                  {...register('name')}
                  disabled={isSaving}
                />
                <FieldError>{errors.name?.message}</FieldError>
              </Field>
              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='provider-rfc'>RFC</FieldLabel>
                <Input id='provider-rfc' {...register('rfc')} disabled={isSaving} />
              </Field>

              {catalogSelect('provider_type_id', 'Tipo de proveedor', CatalogType.PROVIDER_TYPE)}
              {catalogSelect(
                'provider_service_type_id',
                'Tipo de servicio',
                CatalogType.PROVIDER_SERVICE_TYPE
              )}

              <Field className='col-span-12 md:col-span-4'>
                <FieldLabel htmlFor='provider-contact'>Contacto</FieldLabel>
                <Input id='provider-contact' {...register('contact_person')} disabled={isSaving} />
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='provider-phone' required>
                  Teléfono
                </FieldLabel>
                <Input
                  id='provider-phone'
                  type='tel'
                  placeholder='777 123 4567'
                  {...register('phone')}
                  disabled={isSaving}
                />
                <FieldError>{errors.phone?.message}</FieldError>
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='provider-email'>Correo</FieldLabel>
                <Input
                  id='provider-email'
                  type='email'
                  {...register('email')}
                  disabled={isSaving}
                />
                <FieldError>{errors.email?.message}</FieldError>
              </Field>

              <Field className='col-span-6 md:col-span-3'>
                <FieldLabel htmlFor='provider-recurring'>Recurrente</FieldLabel>
                <Controller
                  name='is_recurring'
                  control={control}
                  render={({ field }) => (
                    <Switch
                      id='provider-recurring'
                      checked={field.value}
                      onCheckedChange={field.onChange}
                      disabled={isSaving}
                    />
                  )}
                />
              </Field>
              {isRecurring && (
                <>
                  <Field className='col-span-6 md:col-span-4'>
                    <FieldLabel htmlFor='provider-amount'>Monto estimado</FieldLabel>
                    <div className='relative'>
                      <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                        $
                      </span>
                      <Input
                        id='provider-amount'
                        type='number'
                        step='0.01'
                        min={0}
                        className='pl-7'
                        {...register('estimated_amount', {
                          setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
                        })}
                        disabled={isSaving}
                      />
                    </div>
                  </Field>
                  <Field className='col-span-6 md:col-span-3'>
                    <FieldLabel htmlFor='provider-day'>Día de pago</FieldLabel>
                    <Input
                      id='provider-day'
                      type='number'
                      min={1}
                      max={31}
                      placeholder='15'
                      {...register('usual_payment_day', {
                        setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
                      })}
                      disabled={isSaving}
                    />
                  </Field>
                </>
              )}

              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='provider-bank'>Banco</FieldLabel>
                <Input id='provider-bank' {...register('bank')} disabled={isSaving} />
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='provider-clabe'>CLABE</FieldLabel>
                <Input
                  id='provider-clabe'
                  maxLength={18}
                  {...register('clabe')}
                  disabled={isSaving}
                />
              </Field>
              <Field className='col-span-6 md:col-span-4'>
                <FieldLabel htmlFor='provider-active'>Activo</FieldLabel>
                <Controller
                  name='is_active'
                  control={control}
                  render={({ field }) => (
                    <Switch
                      id='provider-active'
                      checked={field.value}
                      onCheckedChange={field.onChange}
                      disabled={isSaving}
                    />
                  )}
                />
              </Field>

              <Field className='col-span-12'>
                <FieldLabel htmlFor='provider-notes'>Notas</FieldLabel>
                <Textarea
                  id='provider-notes'
                  rows={2}
                  className='resize-none'
                  {...register('notes')}
                  disabled={isSaving}
                />
              </Field>
            </div>
          </FieldGroup>
        </form>

        <SheetFooter className='shrink-0 px-6 pb-6 pt-4 border-t border-border flex-row justify-end gap-2'>
          <Button type='button' variant='outline' onClick={onClose} disabled={isSaving}>
            Cancelar
          </Button>
          <Button
            type='button'
            onClick={() => void doSubmit()}
            disabled={isSaving}
            className='min-w-[130px]'
          >
            {isSaving ? 'Guardando…' : 'Guardar proveedor'}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}

export const ProviderListPage = () => {
  const { onPageChanged, onSearch, onReset, onReport, isExporting, paramsSearch } =
    useBaseList<IProvider>({
      store: useProviderStore,
      resource: ProviderResource,
      autoInit: true,
      initialSearch: {
        sort: [{ field: 'name', direction: 'asc' }],
        includes: [{ relation: 'providerType' }, { relation: 'serviceType' }],
      },
    })
  const { entities, pagination, isLoading } = useProviderStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const canEdit = usePermissions('update.provider')

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selected, setSelected] = useState<IProvider | null>(null)

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Proveedores'
        description='Proveedores de servicios del condominio'
        actions={
          <>
            <PermissionGuard permission='report.provider'>
              <Button
                variant='outline'
                onClick={() => onReport('Provider', 'xlsx', 'proveedores')}
                disabled={isExporting}
                className='flex items-center gap-2'
              >
                {isExporting ? (
                  <Loader2 className='h-4 w-4 animate-spin' />
                ) : (
                  <Download className='h-4 w-4' />
                )}
                Descargar
              </Button>
            </PermissionGuard>
            <PermissionGuard permission='create.provider'>
              <Button
                className='rounded-full'
                onClick={() => {
                  setSelected(null)
                  setModalMode(CrudMode.CREATE)
                  setModalOpen(true)
                }}
              >
                <Plus className='size-4' />
                Nuevo proveedor
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
          placeholder='Buscar proveedores…'
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState title='Sin proveedores registrados' />
        ) : (
          <>
            <ResponsiveDataTable<IProvider>
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Proveedor</TableHead>
                    <TableHead scope='col'>Servicio</TableHead>
                    <TableHead scope='col' className='hidden md:table-cell'>
                      Contacto
                    </TableHead>
                    <TableHead scope='col' className='hidden md:table-cell text-right'>
                      Estimado
                    </TableHead>
                    <TableHead scope='col'>Recurrente</TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    {canEdit && <TableHead scope='col' className='text-right'></TableHead>}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(provider) => (
                <TableRow key={provider.id}>
                  <TableCell className='font-medium'>{provider.name}</TableCell>
                  <TableCell>{provider.service_type?.name ?? '—'}</TableCell>
                  <TableCell className='hidden md:table-cell text-[12.5px] text-[var(--ink-3)]'>
                    {[provider.contact_person, provider.phone].filter(Boolean).join(' · ') || '—'}
                  </TableCell>
                  <TableCell className='hidden md:table-cell text-right tabular-nums'>
                    {provider.estimated_amount != null
                      ? displayMoney(provider.estimated_amount)
                      : '—'}
                  </TableCell>
                  <TableCell>
                    {provider.is_recurring
                      ? `Sí${provider.usual_payment_day ? ` · día ${provider.usual_payment_day}` : ''}`
                      : 'No'}
                  </TableCell>
                  <TableCell>
                    <StatusPill tone={provider.is_active ? 'g' : 'r'}>
                      {provider.is_active ? 'Activo' : 'Inactivo'}
                    </StatusPill>
                  </TableCell>
                  {canEdit && (
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      <Button
                        variant='ghost'
                        size='icon'
                        className='size-8'
                        onClick={() => {
                          setSelected(provider)
                          setModalMode(CrudMode.EDIT)
                          setModalOpen(true)
                        }}
                        aria-label={`Editar ${provider.name}`}
                      >
                        <Pencil className='size-4' />
                      </Button>
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(provider) => provider.name}
              renderCardFields={(provider) => [
                { label: 'Servicio', value: provider.service_type?.name ?? '—' },
                {
                  label: 'Contacto',
                  value:
                    [provider.contact_person, provider.phone].filter(Boolean).join(' · ') || '—',
                },
                {
                  label: 'Estimado',
                  value:
                    provider.estimated_amount != null
                      ? displayMoney(provider.estimated_amount)
                      : '—',
                },
                {
                  label: 'Recurrente',
                  value: provider.is_recurring
                    ? `Sí${provider.usual_payment_day ? ` · día ${provider.usual_payment_day}` : ''}`
                    : 'No',
                },
                {
                  label: 'Estado',
                  value: (
                    <StatusPill tone={provider.is_active ? 'g' : 'r'}>
                      {provider.is_active ? 'Activo' : 'Inactivo'}
                    </StatusPill>
                  ),
                },
              ]}
              renderActions={
                canEdit
                  ? (provider) => (
                      <Button
                        variant='ghost'
                        size='icon'
                        className='size-8'
                        onClick={() => {
                          setSelected(provider)
                          setModalMode(CrudMode.EDIT)
                          setModalOpen(true)
                        }}
                        aria-label={`Editar ${provider.name}`}
                      >
                        <Pencil className='size-4' />
                      </Button>
                    )
                  : undefined
              }
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <ProviderFormModal
        isOpen={modalOpen}
        mode={modalMode}
        entity={selected}
        onClose={() => {
          setModalOpen(false)
          setSelected(null)
        }}
      />
    </div>
  )
}
