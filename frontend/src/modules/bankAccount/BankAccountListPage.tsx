import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { Textarea } from '@/components/ui/textarea'
import {
  BANK_ACCOUNT_TYPE,
  bankAccountFormSchema,
  digitsOnly,
  maskCardNumber,
  type BankAccountFormValues,
  type IBankAccount,
} from '@/modules/bankAccount/schema'
import { BankAccountResource } from '@/modules/bankAccount/services/BankAccountResource'
import { useBankAccountStore } from '@/modules/bankAccount/store/bankAccountStore'
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
import { CatalogType, useCatalogs, type ICatalogItem } from '@/shared/hooks/useCatalogs'
import { usePermissions } from '@/shared/hooks/usePermissions'
import {
  displayMoney,
  minorUnitsToPesos,
  moneyToMinorUnits,
  pesosToMinorUnits,
} from '@/shared/utils/money'
import { zodResolver } from '@hookform/resolvers/zod'
import { Download, Landmark, Loader2, Pencil, Plus, Wallet } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { useShallow } from 'zustand/react/shallow'

interface AccountFormModalProps {
  isOpen: boolean
  mode: CrudMode
  entity: IBankAccount | null
  onClose: () => void
}

function AccountFormModal({ isOpen, mode, entity, onClose }: AccountFormModalProps) {
  const isSaving = useBankAccountStore((s) => s.isSaving)
  const isEdit = mode === CrudMode.EDIT

  const crud = useBaseCrud<IBankAccount, Record<string, unknown>>({
    store: useBankAccountStore,
    resource: {
      save: BankAccountResource.create,
      update: BankAccountResource.update,
    },
    mode,
    entityId: entity?.id,
    initialData: entity,
  })

  const defaultValues = useMemo<BankAccountFormValues>(
    () => ({
      name: entity?.name ?? '',
      bank_account_type_id: entity?.bank_account_type_id ?? BANK_ACCOUNT_TYPE.BANK,
      bank_account_purpose_id: entity?.bank_account_purpose_id ?? '',
      bank: entity?.bank ?? '',
      account_number: entity?.account_number ?? '',
      clabe: entity?.clabe ?? '',
      card_number: entity?.card_number ?? '',
      initial_balance:
        entity?.initial_balance != null
          ? minorUnitsToPesos(moneyToMinorUnits(entity.initial_balance))
          : null,
      is_primary: entity?.is_primary ?? false,
      is_active: entity?.is_active ?? true,
      notes: entity?.notes ?? '',
    }),
    [entity]
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    watch,
    formState: { errors },
  } = useForm<BankAccountFormValues>({
    resolver: zodResolver(bankAccountFormSchema),
    defaultValues,
  })

  useEffect(() => {
    if (isOpen) reset(defaultValues)
  }, [isOpen, reset, defaultValues])

  // §infra-cost: el modal se renderiza siempre (isOpen togglea Radix, no el mount),
  // así que gateamos los catálogos del formulario con `enabled: isOpen` para que
  // POST /selects no se dispare al entrar a la lista, solo al abrir el Sheet.
  const { catalogs } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.BANK_ACCOUNT_TYPE]: { _sort: 'order' },
        [CatalogType.BANK_ACCOUNT_PURPOSE]: { _sort: 'order' },
      }),
      []
    ),
    { enabled: isOpen }
  )

  const typeId = watch('bank_account_type_id')
  const isBank = typeId === BANK_ACCOUNT_TYPE.BANK

  const doSubmit = handleSubmit(async (values) => {
    const payload: Record<string, unknown> = {
      ...values,
      bank: values.bank || null,
      account_number: values.account_number || null,
      clabe: values.clabe || null,
      // La tarjeta viaja normalizada a dígitos: el usuario puede teclearla con espacios.
      card_number: values.card_number ? digitsOnly(values.card_number) : null,
      notes: values.notes || null,
      initial_balance:
        values.initial_balance != null ? pesosToMinorUnits(values.initial_balance) : 0,
    }
    if (isEdit) {
      // §9.6: el saldo inicial no se reescribe en edición
      delete payload.initial_balance
    }
    const result = await crud.submit(payload)
    if (result) onClose()
  })

  // Tipo es un enum fijo (banco/caja): mantiene el Select shadcn simple.
  const catalogSelect = (name: 'bank_account_type_id', label: string, catalog: CatalogType) => (
    <Field className='col-span-12 md:col-span-6'>
      <FieldLabel htmlFor={`account-${name}`} required>
        {label}
      </FieldLabel>
      <Controller
        name={name}
        control={control}
        render={({ field }) => (
          <Select
            value={field.value || undefined}
            onValueChange={field.onChange}
            disabled={isSaving}
          >
            <SelectTrigger id={`account-${name}`} className='w-full'>
              <SelectValue placeholder='Seleccionar…' />
            </SelectTrigger>
            <SelectContent>
              {catalogs[catalog]?.map((item) => (
                <SelectItem key={item.id} value={item.id}>
                  {(item as { name?: string }).name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      />
      <FieldError>{errors[name]?.message}</FieldError>
    </Field>
  )

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side='right'
        className='flex flex-col gap-0 p-0 w-full sm:max-w-xl'
        aria-describedby={undefined}
      >
        <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
          <SheetTitle>{isEdit ? 'Editar cuenta' : 'Nueva cuenta'}</SheetTitle>
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
              <Field className='col-span-12'>
                <FieldLabel htmlFor='account-name' required>
                  Nombre
                </FieldLabel>
                <Input
                  id='account-name'
                  placeholder='HSBC Principal / Caja efectivo'
                  {...register('name')}
                  disabled={isSaving}
                />
                <FieldError>{errors.name?.message}</FieldError>
              </Field>

              {catalogSelect('bank_account_type_id', 'Tipo', CatalogType.BANK_ACCOUNT_TYPE)}

              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='account-bank_account_purpose_id' required>
                  Propósito
                </FieldLabel>
                <Controller
                  name='bank_account_purpose_id'
                  control={control}
                  render={({ field }) => (
                    <SearchSelect<ICatalogItem>
                      id='account-bank_account_purpose_id'
                      value={field.value}
                      onChange={(v) => field.onChange((v as string | null) ?? '')}
                      options={catalogs[CatalogType.BANK_ACCOUNT_PURPOSE] ?? []}
                      isDisabled={isSaving}
                      invalid={!!errors.bank_account_purpose_id}
                      placeholder='Seleccionar…'
                    />
                  )}
                />
                <FieldError>{errors.bank_account_purpose_id?.message}</FieldError>
              </Field>

              {isBank && (
                <>
                  <Field className='col-span-12 md:col-span-6'>
                    <FieldLabel htmlFor='account-bank'>Banco</FieldLabel>
                    <Input
                      id='account-bank'
                      placeholder='HSBC'
                      {...register('bank')}
                      disabled={isSaving}
                    />
                  </Field>
                  <Field className='col-span-12 md:col-span-6'>
                    <FieldLabel htmlFor='account-number'>Número de cuenta</FieldLabel>
                    <Input
                      id='account-number'
                      placeholder='****1234'
                      {...register('account_number')}
                      disabled={isSaving}
                    />
                  </Field>
                  <Field className='col-span-12 md:col-span-6'>
                    <FieldLabel htmlFor='account-clabe'>CLABE</FieldLabel>
                    <Input
                      id='account-clabe'
                      placeholder='18 dígitos'
                      maxLength={18}
                      {...register('clabe')}
                      disabled={isSaving}
                    />
                    <FieldError>{errors.clabe?.message}</FieldError>
                  </Field>
                  <Field className='col-span-12 md:col-span-6'>
                    <FieldLabel htmlFor='account-card_number'>Número de tarjeta</FieldLabel>
                    <Input
                      id='account-card_number'
                      inputMode='numeric'
                      autoComplete='off'
                      placeholder='16 dígitos'
                      maxLength={23}
                      {...register('card_number')}
                      disabled={isSaving}
                    />
                    <FieldDescription>
                      Tarjeta ligada a la cuenta. En la lista solo se muestran los últimos 4 dígitos
                    </FieldDescription>
                    <FieldError>{errors.card_number?.message}</FieldError>
                  </Field>
                </>
              )}

              {!isEdit && (
                <Field className='col-span-12 md:col-span-6'>
                  <FieldLabel htmlFor='account-initial'>Saldo inicial</FieldLabel>
                  <div className='relative'>
                    <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                      $
                    </span>
                    <Input
                      id='account-initial'
                      type='number'
                      step='0.01'
                      min={0}
                      className='pl-7'
                      placeholder='0.00'
                      {...register('initial_balance', {
                        setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
                      })}
                      disabled={isSaving}
                    />
                  </div>
                  <FieldDescription>
                    El saldo actual parte de aquí y se recalcula con pagos y egresos
                  </FieldDescription>
                </Field>
              )}

              <Field className='col-span-6 md:col-span-3'>
                <FieldLabel htmlFor='account-primary'>Principal</FieldLabel>
                <Controller
                  name='is_primary'
                  control={control}
                  render={({ field }) => (
                    <Switch
                      id='account-primary'
                      checked={field.value}
                      onCheckedChange={field.onChange}
                      disabled={isSaving}
                    />
                  )}
                />
              </Field>
              <Field className='col-span-6 md:col-span-3'>
                <FieldLabel htmlFor='account-active'>Activa</FieldLabel>
                <Controller
                  name='is_active'
                  control={control}
                  render={({ field }) => (
                    <Switch
                      id='account-active'
                      checked={field.value}
                      onCheckedChange={field.onChange}
                      disabled={isSaving}
                    />
                  )}
                />
              </Field>

              <Field className='col-span-12'>
                <FieldLabel htmlFor='account-notes'>Notas</FieldLabel>
                <Textarea
                  id='account-notes'
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
            className='min-w-[120px]'
          >
            {isSaving ? 'Guardando…' : 'Guardar cuenta'}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}

export const BankAccountListPage = () => {
  const { onPageChanged, onSearch, onReset, onReport, isExporting, paramsSearch } =
    useBaseList<IBankAccount>({
      store: useBankAccountStore,
      resource: BankAccountResource,
      autoInit: true,
      initialSearch: {
        sort: [{ field: 'name', direction: 'asc' }],
        includes: [{ relation: 'type' }, { relation: 'purpose' }],
      },
    })
  const { entities, pagination, isLoading } = useBankAccountStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )
  const canEdit = usePermissions('update.bank_account')

  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selected, setSelected] = useState<IBankAccount | null>(null)

  const totalBalance = entities.reduce(
    (sum, account) => sum + moneyToMinorUnits(account.current_balance ?? 0),
    0
  )

  const handleCreate = useCallback(() => {
    setSelected(null)
    setModalMode(CrudMode.CREATE)
    setModalOpen(true)
  }, [])

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Cuentas Bancarias'
        description='Cuentas y caja del condominio — el saldo se recalcula con cada movimiento'
        actions={
          <>
            <PermissionGuard permission='report.bank_account'>
              <Button
                variant='outline'
                onClick={() => onReport('BankAccount', 'xlsx', 'cuentas-bancarias')}
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
            <PermissionGuard permission='create.bank_account'>
              <Button className='rounded-full' onClick={handleCreate}>
                <Plus className='size-4' />
                Nueva cuenta
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
          placeholder='Buscar cuentas…'
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState
            title='Sin cuentas registradas'
            description='Registra al menos la caja de efectivo.'
          />
        ) : (
          <>
            <ResponsiveDataTable<IBankAccount>
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Cuenta</TableHead>
                    <TableHead scope='col' className='hidden lg:table-cell'>
                      Propósito
                    </TableHead>
                    <TableHead scope='col' className='hidden lg:table-cell'>
                      Datos
                    </TableHead>
                    <TableHead scope='col' className='text-right'>
                      Saldo actual
                    </TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    {canEdit && <TableHead scope='col' className='text-right'></TableHead>}
                  </TableRow>
                </TableHeader>
              }
              renderRow={(account) => (
                <TableRow key={account.id}>
                  <TableCell>
                    <div className='flex items-center gap-2.5'>
                      <span className='grid size-8 flex-none place-items-center rounded-md bg-[var(--surface-2)] text-[var(--green-deep)]'>
                        {account.bank_account_type_id === BANK_ACCOUNT_TYPE.CASH ? (
                          <Wallet className='size-4' />
                        ) : (
                          <Landmark className='size-4' />
                        )}
                      </span>
                      <span className='font-medium'>
                        {account.name}
                        {account.is_primary && (
                          <span className='ml-2 rounded-full bg-[var(--green-bg)] px-2 py-0.5 text-[10.5px] font-semibold text-[var(--green-deep)]'>
                            Principal
                          </span>
                        )}
                      </span>
                    </div>
                  </TableCell>
                  <TableCell className='hidden lg:table-cell'>
                    {account.purpose?.name ?? '—'}
                  </TableCell>
                  <TableCell className='hidden lg:table-cell text-[12.5px] text-[var(--ink-3)]'>
                    {[account.bank, account.account_number, maskCardNumber(account.card_number)]
                      .filter(Boolean)
                      .join(' · ') || '—'}
                  </TableCell>
                  <TableCell className='text-right font-semibold tabular-nums'>
                    {displayMoney(account.current_balance ?? 0)}
                  </TableCell>
                  <TableCell>
                    <StatusPill tone={account.is_active ? 'g' : 'r'}>
                      {account.is_active ? 'Activa' : 'Inactiva'}
                    </StatusPill>
                  </TableCell>
                  {canEdit && (
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      <Button
                        variant='ghost'
                        size='icon'
                        className='size-8'
                        onClick={() => {
                          setSelected(account)
                          setModalMode(CrudMode.EDIT)
                          setModalOpen(true)
                        }}
                        aria-label={`Editar ${account.name}`}
                      >
                        <Pencil className='size-4' />
                      </Button>
                    </TableCell>
                  )}
                </TableRow>
              )}
              renderCardTitle={(account) => account.name}
              renderCardFields={(account) => [
                { label: 'Propósito', value: account.purpose?.name ?? '—' },
                {
                  label: 'Datos',
                  value:
                    [account.bank, account.account_number, maskCardNumber(account.card_number)]
                      .filter(Boolean)
                      .join(' · ') || '—',
                },
                {
                  label: 'Saldo actual',
                  value: (
                    <span className='font-semibold tabular-nums'>
                      {displayMoney(account.current_balance ?? 0)}
                    </span>
                  ),
                },
                {
                  label: 'Estado',
                  value: (
                    <StatusPill tone={account.is_active ? 'g' : 'r'}>
                      {account.is_active ? 'Activa' : 'Inactiva'}
                    </StatusPill>
                  ),
                },
              ]}
              renderActions={
                canEdit
                  ? (account) => (
                      <Button
                        variant='ghost'
                        size='icon'
                        className='size-8'
                        onClick={() => {
                          setSelected(account)
                          setModalMode(CrudMode.EDIT)
                          setModalOpen(true)
                        }}
                        aria-label={`Editar ${account.name}`}
                      >
                        <Pencil className='size-4' />
                      </Button>
                    )
                  : undefined
              }
              footer={
                <div className='flex items-center justify-between border-t border-border bg-[var(--surface-2)] px-4 py-3'>
                  <span className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
                    Total en cuentas
                  </span>
                  <span className='text-[16px] font-semibold tabular-nums'>
                    {displayMoney(totalBalance)}
                  </span>
                </div>
              }
            />
            <div className='flex items-center justify-between rounded-xl border border-border bg-card px-4 py-3 md:hidden'>
              <span className='text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
                Total en cuentas
              </span>
              <span className='text-[16px] font-semibold tabular-nums'>
                {displayMoney(totalBalance)}
              </span>
            </div>
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <AccountFormModal
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
