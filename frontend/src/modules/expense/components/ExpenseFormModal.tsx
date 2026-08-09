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
import { Textarea } from '@/components/ui/textarea'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import {
  BENEFICIARY_TYPE,
  expenseFormSchema,
  type ExpenseFormValues,
  type IExpense,
} from '@/modules/expense/schema'
import { ExpenseResource } from '@/modules/expense/services/ExpenseResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { CrudMode } from '@/shared/enums/CrudMode'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { minorUnitsToPesos, moneyToMinorUnits, pesosToMinorUnits } from '@/shared/utils/money'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Paperclip } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Controller, useForm, type Control, type FieldErrors } from 'react-hook-form'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface ExpenseFormModalProps {
  isOpen: boolean
  mode: CrudMode
  entity: IExpense | null
  onClose: () => void
  onSaved: () => void
}

type CatalogSelectField =
  | 'expense_category_id'
  | 'payment_method_id'
  | 'bank_account_id'
  | 'employee_id'
  | 'provider_id'

interface CatalogItem {
  id: string
  name?: string
  full_name?: string
}

// ---------------------------------------------------------------------------
// Module-level constants
// ---------------------------------------------------------------------------

const CATALOG_REQUEST = {
  [CatalogType.EXPENSE_CATEGORY]: { _sort: 'name' },
  [CatalogType.BENEFICIARY_TYPE]: { _sort: 'order' },
  [CatalogType.PAYMENT_METHOD]: { _sort: 'order' },
  [CatalogType.BANK_ACCOUNT]: { _sort: 'name' },
  [CatalogType.COLLABORATOR]: { _sort: 'name' },
  [CatalogType.PROVIDER]: { _sort: 'name' },
} as const

// ---------------------------------------------------------------------------
// Pure helpers
// ---------------------------------------------------------------------------

function entityAmount(e: IExpense): number {
  if (e.amount == null) return 0
  return minorUnitsToPesos(moneyToMinorUnits(e.amount))
}

function entityPaidAt(e: IExpense): string {
  if (e.paid_at) return e.paid_at.slice(0, 10)
  return new Date().toISOString().slice(0, 10)
}

function buildEntityDefaults(e: IExpense): ExpenseFormValues {
  return {
    concept: e.concept ?? '',
    description: (e.description as string | undefined) ?? '',
    paid_at: entityPaidAt(e),
    amount: entityAmount(e),
    expense_category_id: e.expense_category_id ?? '',
    beneficiary_type_id: e.beneficiary_type_id ?? BENEFICIARY_TYPE.OTHER,
    employee_id: e.employee_id ?? undefined,
    provider_id: e.provider_id ?? undefined,
    beneficiary_text: e.beneficiary_text ?? '',
    payment_method_id: e.payment_method_id ?? '',
    bank_account_id: e.bank_account_id ?? '',
    reference: e.reference ?? '',
    notes: e.notes ?? '',
  }
}

const BLANK_DEFAULTS: ExpenseFormValues = {
  concept: '',
  description: '',
  paid_at: new Date().toISOString().slice(0, 10),
  amount: 0,
  expense_category_id: '',
  beneficiary_type_id: BENEFICIARY_TYPE.OTHER,
  employee_id: undefined,
  provider_id: undefined,
  beneficiary_text: '',
  payment_method_id: '',
  bank_account_id: '',
  reference: '',
  notes: '',
}

function buildDefaultValues(entity: IExpense | null): ExpenseFormValues {
  return entity ? buildEntityDefaults(entity) : BLANK_DEFAULTS
}

function buildBeneficiaryPayload(
  values: ExpenseFormValues,
  beneficiaryTypeId: string
): Record<string, unknown> {
  return {
    employee_id: beneficiaryTypeId === BENEFICIARY_TYPE.EMPLOYEE ? values.employee_id : null,
    provider_id: beneficiaryTypeId === BENEFICIARY_TYPE.PROVIDER ? values.provider_id : null,
    beneficiary_text:
      beneficiaryTypeId === BENEFICIARY_TYPE.OTHER ? values.beneficiary_text : null,
  }
}

function buildCreatePayload(
  basePayload: Record<string, unknown>,
  values: ExpenseFormValues
): Record<string, unknown> {
  const paidAt = new Date(values.paid_at)
  return {
    ...basePayload,
    paid_at: values.paid_at,
    amount: pesosToMinorUnits(values.amount),
    year: paidAt.getFullYear(),
    month: paidAt.getMonth() + 1,
    payment_method_id: values.payment_method_id,
    bank_account_id: values.bank_account_id,
    reference: values.reference || null,
  }
}

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

interface CatalogSelectProps {
  name: CatalogSelectField
  label: string
  items: CatalogItem[]
  control: Control<ExpenseFormValues>
  errors: FieldErrors<ExpenseFormValues>
  isPending: boolean
  required?: boolean
}

function CatalogSelect({
  name,
  label,
  items,
  control,
  errors,
  isPending,
  required = true,
}: CatalogSelectProps) {
  const errorMessage = (errors as Record<string, { message?: string } | undefined>)[name]?.message

  return (
    <Field className='col-span-12 md:col-span-6'>
      <FieldLabel htmlFor={`expense-${name}`} required={required}>
        {label}
      </FieldLabel>
      <Controller
        name={name}
        control={control}
        render={({ field }) => (
          <SearchSelect<CatalogItem>
            id={`expense-${name}`}
            value={field.value}
            onChange={(v) => field.onChange((v as string | null) ?? '')}
            options={items}
            isDisabled={isPending}
            invalid={!!errorMessage}
            placeholder='Seleccionar…'
            getOptionLabel={(o) => o.full_name ?? o.name ?? ''}
          />
        )}
      />
      <FieldError>{errorMessage}</FieldError>
    </Field>
  )
}

// ---------------------------------------------------------------------------
// Custom hook — form logic
// ---------------------------------------------------------------------------

interface UseExpenseFormReturn {
  register: ReturnType<typeof useForm<ExpenseFormValues>>['register']
  control: Control<ExpenseFormValues>
  errors: FieldErrors<ExpenseFormValues>
  beneficiaryTypeId: string
  receipt: File | null
  setReceipt: (file: File | null) => void
  isLoading: boolean
  onSubmit: (e?: React.BaseSyntheticEvent) => Promise<void>
}

function useExpenseForm(
  isOpen: boolean,
  isEdit: boolean,
  entity: IExpense | null,
  onClose: () => void,
  onSaved: () => void
): UseExpenseFormReturn {
  const queryClient = useQueryClient()
  const [receipt, setReceipt] = useState<File | null>(null)

  const defaultValues = useMemo<ExpenseFormValues>(
    () => buildDefaultValues(entity),
    [entity]
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    watch,
    formState: { errors },
  } = useForm<ExpenseFormValues>({
    resolver: zodResolver(expenseFormSchema),
    defaultValues,
  })

  useEffect(() => {
    if (!isOpen) return
    reset(defaultValues)
    setReceipt(null)
  }, [isOpen, reset, defaultValues])

  const beneficiaryTypeId = watch('beneficiary_type_id')

  const mutation = useMutation({
    mutationFn: async (values: ExpenseFormValues) => {
      const beneficiaryPayload = buildBeneficiaryPayload(values, beneficiaryTypeId)
      const basePayload: Record<string, unknown> = {
        concept: values.concept,
        description: values.description || null,
        expense_category_id: values.expense_category_id,
        beneficiary_type_id: values.beneficiary_type_id,
        ...beneficiaryPayload,
        notes: values.notes || null,
      }

      const expense = await (isEdit && entity
        ? ExpenseResource.update(entity.id, basePayload)
        : ExpenseResource.create(buildCreatePayload(basePayload, values)))

      if (receipt) await ExpenseResource.uploadMedia(expense.id, receipt)
      return expense
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Egreso actualizado' : 'Egreso registrado')
      void queryClient.invalidateQueries({ queryKey: ['treasury'] })
      onSaved()
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo guardar el egreso'
      )
    },
  })

  return {
    register,
    control,
    errors,
    beneficiaryTypeId,
    receipt,
    setReceipt,
    isLoading: mutation.isPending,
    onSubmit: handleSubmit((values) => mutation.mutate(values)),
  }
}

// ---------------------------------------------------------------------------
// Beneficiary section
// ---------------------------------------------------------------------------

interface BeneficiarySectionProps {
  beneficiaryTypeId: string
  catalogs: ReturnType<typeof useCatalogs>['catalogs']
  control: Control<ExpenseFormValues>
  errors: FieldErrors<ExpenseFormValues>
  isPending: boolean
  register: ReturnType<typeof useForm<ExpenseFormValues>>['register']
}

function BeneficiarySection({
  beneficiaryTypeId,
  catalogs,
  control,
  errors,
  isPending,
  register,
}: BeneficiarySectionProps) {
  const beneficiaryTypes = (catalogs[CatalogType.BENEFICIARY_TYPE] ?? []) as CatalogItem[]
  const collaborators = (catalogs[CatalogType.COLLABORATOR] ?? []) as CatalogItem[]
  const providers = (catalogs[CatalogType.PROVIDER] ?? []) as CatalogItem[]

  return (
    <>
      <Field className='col-span-12 md:col-span-6'>
        <FieldLabel htmlFor='expense-beneficiary-type' required>
          Tipo de beneficiario
        </FieldLabel>
        <Controller
          name='beneficiary_type_id'
          control={control}
          render={({ field }) => (
            <Select
              value={field.value || undefined}
              onValueChange={field.onChange}
              disabled={isPending}
            >
              <SelectTrigger id='expense-beneficiary-type' className='w-full'>
                <SelectValue placeholder='Seleccionar…' />
              </SelectTrigger>
              <SelectContent>
                {beneficiaryTypes.map((item) => (
                  <SelectItem key={item.id} value={item.id}>
                    {item.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          )}
        />
        <FieldError>{errors.beneficiary_type_id?.message}</FieldError>
      </Field>

      {beneficiaryTypeId === BENEFICIARY_TYPE.EMPLOYEE && (
        <CatalogSelect
          name='employee_id'
          label='Empleado'
          items={collaborators}
          control={control}
          errors={errors}
          isPending={isPending}
        />
      )}
      {beneficiaryTypeId === BENEFICIARY_TYPE.PROVIDER && (
        <CatalogSelect
          name='provider_id'
          label='Proveedor'
          items={providers}
          control={control}
          errors={errors}
          isPending={isPending}
        />
      )}
      {beneficiaryTypeId === BENEFICIARY_TYPE.OTHER && (
        <Field className='col-span-12 md:col-span-6'>
          <FieldLabel htmlFor='expense-beneficiary-text' required>
            Beneficiario
          </FieldLabel>
          <Input
            id='expense-beneficiary-text'
            placeholder='CFE, Telmex…'
            {...register('beneficiary_text')}
            disabled={isPending}
          />
          <FieldError>{errors.beneficiary_text?.message}</FieldError>
        </Field>
      )}
    </>
  )
}

// ---------------------------------------------------------------------------
// Payment fields section (only visible in create mode)
// ---------------------------------------------------------------------------

interface PaymentFieldsSectionProps {
  catalogs: ReturnType<typeof useCatalogs>['catalogs']
  control: Control<ExpenseFormValues>
  errors: FieldErrors<ExpenseFormValues>
  isPending: boolean
  register: ReturnType<typeof useForm<ExpenseFormValues>>['register']
}

function PaymentFieldsSection({
  catalogs,
  control,
  errors,
  isPending,
  register,
}: PaymentFieldsSectionProps) {
  const paymentMethods = (catalogs[CatalogType.PAYMENT_METHOD] ?? []) as CatalogItem[]
  const bankAccounts = (catalogs[CatalogType.BANK_ACCOUNT] ?? []) as CatalogItem[]

  return (
    <>
      <CatalogSelect
        name='payment_method_id'
        label='Método de pago'
        items={paymentMethods}
        control={control}
        errors={errors}
        isPending={isPending}
      />
      <CatalogSelect
        name='bank_account_id'
        label='Cuenta origen'
        items={bankAccounts}
        control={control}
        errors={errors}
        isPending={isPending}
      />
      <Field className='col-span-12 md:col-span-6'>
        <FieldLabel htmlFor='expense-reference'>Referencia</FieldLabel>
        <Input
          id='expense-reference'
          placeholder='Núm. de operación'
          {...register('reference')}
          disabled={isPending}
        />
      </Field>
    </>
  )
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Form content — holds ALL form hooks (useExpenseForm + useCatalogs).
// Lives in a child so it MOUNTS only when the Sheet is open, which means the
// catalog /selects requests fire on open, NOT when the list page mounts.
// ---------------------------------------------------------------------------

interface ExpenseFormContentProps {
  mode: CrudMode
  entity: IExpense | null
  onClose: () => void
  onSaved: () => void
}

function ExpenseFormContent({ mode, entity, onClose, onSaved }: ExpenseFormContentProps) {
  const isEdit = mode === CrudMode.EDIT
  const fileRef = useRef<HTMLInputElement>(null)

  // Always-open from here: this component only renders inside the open Sheet.
  const { register, control, errors, beneficiaryTypeId, receipt, setReceipt, isLoading, onSubmit } =
    useExpenseForm(true, isEdit, entity, onClose, onSaved)

  const { catalogs } = useCatalogs(useMemo(() => CATALOG_REQUEST, []))
  const expenseCategories = (catalogs[CatalogType.EXPENSE_CATEGORY] ?? []) as CatalogItem[]

  return (
    <>
      <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
        <SheetTitle>{isEdit ? 'Editar egreso' : 'Nuevo egreso'}</SheetTitle>
      </SheetHeader>

      <form className='min-h-0 flex-1 overflow-y-auto px-6 py-2' onSubmit={onSubmit}>
        <FieldGroup>
          <RequiredFieldsLegend />
          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-12 md:col-span-8'>
              <FieldLabel htmlFor='expense-concept' required>Concepto</FieldLabel>
              <Input
                id='expense-concept'
                placeholder='Pago de jardinería marzo'
                {...register('concept')}
                disabled={isLoading}
              />
              <FieldError>{errors.concept?.message}</FieldError>
            </Field>

            <Field className='col-span-12 md:col-span-4'>
              <FieldLabel htmlFor='expense-amount' required>Monto</FieldLabel>
              <div className='relative'>
                <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                  $
                </span>
                <Input
                  id='expense-amount'
                  type='number'
                  step='0.01'
                  min={0}
                  className='pl-7'
                  placeholder='0.00'
                  {...register('amount', {
                    setValueAs: (v) => (v === '' || v == null ? 0 : Number(v)),
                  })}
                  disabled={isLoading || isEdit}
                />
              </div>
              {isEdit && (
                <FieldDescription>
                  El monto es inmutable; anula y recaptura si fue un error
                </FieldDescription>
              )}
              <FieldError>{errors.amount?.message}</FieldError>
            </Field>

            <Field className='col-span-12 md:col-span-4'>
              <FieldLabel htmlFor='expense-date' required>Fecha de pago</FieldLabel>
              <Input
                id='expense-date'
                type='date'
                {...register('paid_at')}
                disabled={isLoading || isEdit}
              />
              <FieldError>{errors.paid_at?.message}</FieldError>
            </Field>

            <CatalogSelect
              name='expense_category_id'
              label='Categoría'
              items={expenseCategories}
              control={control}
              errors={errors}
              isPending={isLoading}
            />

            <BeneficiarySection
              beneficiaryTypeId={beneficiaryTypeId}
              catalogs={catalogs}
              control={control}
              errors={errors}
              isPending={isLoading}
              register={register}
            />

            {!isEdit && (
              <PaymentFieldsSection
                catalogs={catalogs}
                control={control}
                errors={errors}
                isPending={isLoading}
                register={register}
              />
            )}

            <Field className='col-span-12 md:col-span-6'>
              <FieldLabel htmlFor='expense-receipt'>Comprobante</FieldLabel>
              <input
                ref={fileRef}
                id='expense-receipt'
                type='file'
                accept='image/*,.pdf'
                className='hidden'
                onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
              />
              <Button
                type='button'
                variant='outline'
                className='justify-start'
                onClick={() => fileRef.current?.click()}
                disabled={isLoading}
              >
                <Paperclip className='size-4' />
                {receipt ? receipt.name : 'Subir factura / recibo'}
              </Button>
            </Field>

            <Field className='col-span-12'>
              <FieldLabel htmlFor='expense-desc'>Descripción</FieldLabel>
              <Textarea
                id='expense-desc'
                rows={2}
                className='resize-none'
                {...register('description')}
                disabled={isLoading}
              />
            </Field>
            <Field className='col-span-12'>
              <FieldLabel htmlFor='expense-notes'>Notas</FieldLabel>
              <Textarea
                id='expense-notes'
                rows={2}
                className='resize-none'
                {...register('notes')}
                disabled={isLoading}
              />
            </Field>
          </div>
        </FieldGroup>
      </form>

      <SheetFooter className='shrink-0 px-6 pb-6 pt-4 border-t border-border flex-row justify-end gap-2'>
        <Button type='button' variant='outline' onClick={onClose} disabled={isLoading}>
          Cancelar
        </Button>
        <Button
          type='button'
          onClick={onSubmit}
          disabled={isLoading}
          className='min-w-[140px]'
        >
          {isLoading ? 'Guardando…' : isEdit ? 'Actualizar' : 'Registrar egreso'}
        </Button>
      </SheetFooter>
    </>
  )
}

// ---------------------------------------------------------------------------
// Main component — thin shell. Early-returns null while closed (no hooks here)
// so the form hooks above never run until the modal opens.
// ---------------------------------------------------------------------------

/**
 * Alta/edición de egreso (§9.4). En edición el backend solo permite campos
 * cosméticos (concepto/descr/notas/categoría/beneficiario) — monto, cuenta
 * y fecha son inmutables tras registrarse.
 */
export function ExpenseFormModal({
  isOpen,
  mode,
  entity,
  onClose,
  onSaved,
}: ExpenseFormModalProps) {
  if (!isOpen) return null

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side='right'
        className='flex flex-col gap-0 p-0 w-full sm:max-w-2xl'
        aria-describedby={undefined}
      >
        <ExpenseFormContent mode={mode} entity={entity} onClose={onClose} onSaved={onSaved} />
      </SheetContent>
    </Sheet>
  )
}
