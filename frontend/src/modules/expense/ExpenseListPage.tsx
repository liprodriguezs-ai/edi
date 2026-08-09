import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import { Textarea } from '@/components/ui/textarea'
import { ExpenseFormModal } from '@/modules/expense/components/ExpenseFormModal'
import { EXPENSE_STATUS, type IExpense } from '@/modules/expense/schema'
import { ExpenseResource } from '@/modules/expense/services/ExpenseResource'
import { useExpenseStore } from '@/modules/expense/store/expenseStore'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { Pagination } from '@/shared/components/Pagination'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { SearchList } from '@/shared/components/SearchList'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { useBaseList } from '@/shared/hooks/useBaseList'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { downloadMediaWithSignedUrl } from '@/shared/services/downloadMediaWithSignedUrl'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { displayMoney } from '@/shared/utils/money'
import { useMutation } from '@tanstack/react-query'
import { Ban, Download, FileText, Loader2, Pencil, Plus } from 'lucide-react'
import { useState } from 'react'
import { useShallow } from 'zustand/react/shallow'
import { CrudMode } from '@/shared/enums/CrudMode'

const PERMISSIONS = {
  canEdit: 'update.expense',
  canVoid: 'void.expense',
} as const

/** Quién recibió el egreso (empleado, proveedor o texto libre) */
function beneficiaryLabel(expense: IExpense): string {
  return (
    expense.employee?.full_name ??
    expense.employee?.name ??
    expense.provider?.name ??
    expense.beneficiary_text ??
    '—'
  )
}

export const ExpenseListPage = () => {
  const permissions = useMultiplePermissions(PERMISSIONS)
  const [modalOpen, setModalOpen] = useState(false)
  const [modalMode, setModalMode] = useState<CrudMode>(CrudMode.CREATE)
  const [selected, setSelected] = useState<IExpense | null>(null)
  const [voidTarget, setVoidTarget] = useState<IExpense | null>(null)
  const [voidReason, setVoidReason] = useState('')
  const [downloadingId, setDownloadingId] = useState<string | null>(null)

  const downloadMedia = async (id: string, collection: string, fileName: string) => {
    setDownloadingId(id + collection)
    try {
      await downloadMediaWithSignedUrl({
        mediaId: id,
        fileName,
        errorMessage: 'No se pudo descargar el archivo',
      })
    } finally {
      setDownloadingId(null)
    }
  }

  const { onPageChanged, onSearch, onReset, onReport, isExporting, paramsSearch } =
    useBaseList<IExpense>({
      store: useExpenseStore,
      resource: ExpenseResource,
      autoInit: true,
      initialSearch: {
        sort: [{ field: 'paid_at', direction: 'desc' }],
        includes: [
          { relation: 'category' },
          { relation: 'employee' },
          { relation: 'provider' },
          { relation: 'bank_account' },
          { relation: 'status' },
          { relation: 'media' },
        ],
      },
    })
  const { entities, pagination, isLoading } = useExpenseStore(
    useShallow((s) => ({ entities: s.entities, pagination: s.pagination, isLoading: s.isLoading }))
  )

  const refresh = () => onSearch({ ...paramsSearch })

  const voidMutation = useMutation({
    mutationFn: ({ expense, reason }: { expense: IExpense; reason: string }) =>
      ExpenseResource.void(expense.id, reason),
    onSuccess: () => {
      toast.success('Egreso anulado — el saldo de la cuenta se restauró')
      setVoidTarget(null)
      setVoidReason('')
      refresh()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo anular el egreso'
      ),
  })

  return (
    <div className='space-y-6'>
      <PageHeader
        title='Egresos'
        description='Gastos del condominio — descuentan del saldo de la cuenta'
        actions={
          <>
            <PermissionGuard permission='report.expense'>
              <Button
                variant='outline'
                onClick={() => onReport('Expense', 'xlsx', 'egresos')}
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
            <PermissionGuard permission='create.expense'>
              <Button
                className='rounded-full'
                onClick={() => {
                  setSelected(null)
                  setModalMode(CrudMode.CREATE)
                  setModalOpen(true)
                }}
              >
                <Plus className='size-4' />
                Nuevo egreso
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
          placeholder='Buscar por concepto o referencia…'
        />

        {isLoading ? (
          <TableLoadingState />
        ) : entities.length === 0 ? (
          <TableEmptyState title='Sin egresos registrados' />
        ) : (
          <>
            <ResponsiveDataTable<IExpense>
              breakpoint='lg'
              rows={entities}
              header={
                <TableHeader>
                  <TableRow>
                    <TableHead scope='col'>Concepto</TableHead>
                    <TableHead scope='col'>Categoría</TableHead>
                    <TableHead scope='col'>Beneficiario</TableHead>
                    <TableHead scope='col'>Cuenta</TableHead>
                    <TableHead scope='col'>Fecha</TableHead>
                    <TableHead scope='col' className='text-right'>
                      Monto
                    </TableHead>
                    <TableHead scope='col'>Estado</TableHead>
                    <TableHead scope='col' className='text-right'></TableHead>
                  </TableRow>
                </TableHeader>
              }
              renderRow={(expense) => {
                const voided = expense.expense_status_id === EXPENSE_STATUS.VOIDED
                return (
                  <TableRow key={expense.id} className={voided ? 'opacity-50' : ''}>
                    <TableCell className='font-medium'>{expense.concept}</TableCell>
                    <TableCell>{expense.category?.name ?? '—'}</TableCell>
                    <TableCell>{beneficiaryLabel(expense)}</TableCell>
                    <TableCell className='text-[12.5px] text-[var(--ink-3)]'>
                      {expense.bank_account?.name ?? '—'}
                    </TableCell>
                    <TableCell>{displayFormatDate(expense.paid_at, 'mediumDate')}</TableCell>
                    <TableCell className='text-right font-semibold tabular-nums text-[#C0533C]'>
                      −{displayMoney(expense.amount)}
                    </TableCell>
                    <TableCell>
                      <StatusPill tone={voided ? 'r' : 'g'}>
                        {expense.status?.name ?? (voided ? 'Anulado' : 'Pagado')}
                      </StatusPill>
                    </TableCell>
                    <TableCell className='text-right' onClick={(e) => e.stopPropagation()}>
                      <div className='flex justify-end gap-1'>
                        {expense.media
                          ?.filter(
                            (m) => m.collection_name === 'receipt' || m.collection_name === 'cfdi'
                          )
                          .map((m) => (
                            <Button
                              key={m.id}
                              variant='ghost'
                              size='icon'
                              className='size-8'
                              onClick={() =>
                                void downloadMedia(m.id, m.collection_name, m.file_name)
                              }
                              disabled={downloadingId === m.id + m.collection_name}
                              aria-label={`Descargar ${m.collection_name === 'cfdi' ? 'CFDI' : 'comprobante'} de ${expense.concept}`}
                              title={
                                m.collection_name === 'cfdi'
                                  ? 'Descargar CFDI'
                                  : 'Descargar comprobante'
                              }
                            >
                              {m.collection_name === 'cfdi' ? (
                                <FileText className='size-4' />
                              ) : (
                                <Download className='size-4' />
                              )}
                            </Button>
                          ))}
                        {!voided && (
                          <>
                            {permissions.canEdit && (
                              <Button
                                variant='ghost'
                                size='icon'
                                className='size-8'
                                onClick={() => {
                                  setSelected(expense)
                                  setModalMode(CrudMode.EDIT)
                                  setModalOpen(true)
                                }}
                                aria-label={`Editar gasto`}
                              >
                                <Pencil className='size-4' />
                              </Button>
                            )}
                            {permissions.canVoid && (
                              <Button
                                variant='ghost'
                                size='icon'
                                className='size-8 text-destructive hover:text-destructive'
                                onClick={() => setVoidTarget(expense)}
                                aria-label={`Anular egreso ${expense.concept}`}
                                title='Anular egreso'
                              >
                                <Ban className='size-4' />
                              </Button>
                            )}
                          </>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                )
              }}
              renderCardTitle={(expense) => expense.concept}
              renderCardFields={(expense) => {
                const voided = expense.expense_status_id === EXPENSE_STATUS.VOIDED
                return [
                  {
                    label: 'Monto',
                    value: (
                      <span className='font-semibold tabular-nums text-[#C0533C]'>
                        −{displayMoney(expense.amount)}
                      </span>
                    ),
                  },
                  { label: 'Categoría', value: expense.category?.name ?? '—' },
                  { label: 'Beneficiario', value: beneficiaryLabel(expense) },
                  { label: 'Cuenta', value: expense.bank_account?.name ?? '—' },
                  { label: 'Fecha', value: displayFormatDate(expense.paid_at, 'mediumDate') },
                  {
                    label: 'Estado',
                    value: (
                      <StatusPill tone={voided ? 'r' : 'g'}>
                        {expense.status?.name ?? (voided ? 'Anulado' : 'Pagado')}
                      </StatusPill>
                    ),
                  },
                ]
              }}
              renderActions={(expense) => {
                const voided = expense.expense_status_id === EXPENSE_STATUS.VOIDED
                return (
                  <div className='flex justify-end gap-1'>
                    {expense.media
                      ?.filter(
                        (m) => m.collection_name === 'receipt' || m.collection_name === 'cfdi'
                      )
                      .map((m) => (
                        <Button
                          key={m.id}
                          variant='ghost'
                          size='icon'
                          className='size-8'
                          onClick={() => void downloadMedia(m.id, m.collection_name, m.file_name)}
                          disabled={downloadingId === m.id + m.collection_name}
                          aria-label={`Descargar ${m.collection_name === 'cfdi' ? 'CFDI' : 'comprobante'} de ${expense.concept}`}
                          title={
                            m.collection_name === 'cfdi'
                              ? 'Descargar CFDI'
                              : 'Descargar comprobante'
                          }
                        >
                          {m.collection_name === 'cfdi' ? (
                            <FileText className='size-4' />
                          ) : (
                            <Download className='size-4' />
                          )}
                        </Button>
                      ))}
                    {!voided && permissions.canEdit && (
                      <Button
                        variant='ghost'
                        size='icon'
                        className='size-8'
                        onClick={() => {
                          setSelected(expense)
                          setModalMode(CrudMode.EDIT)
                          setModalOpen(true)
                        }}
                        aria-label={`Editar gasto ${expense.concept}`}
                      >
                        <Pencil className='size-4' />
                      </Button>
                    )}
                    {!voided && permissions.canVoid && (
                      <Button
                        variant='ghost'
                        size='icon'
                        className='size-8 text-destructive hover:text-destructive'
                        onClick={() => setVoidTarget(expense)}
                        aria-label={`Anular egreso ${expense.concept}`}
                        title='Anular egreso'
                      >
                        <Ban className='size-4' />
                      </Button>
                    )}
                  </div>
                )
              }}
            />
            <Pagination pagination={pagination} onPageChanged={onPageChanged} />
          </>
        )}
      </section>

      <ExpenseFormModal
        isOpen={modalOpen}
        mode={modalMode}
        entity={selected}
        onClose={() => {
          setModalOpen(false)
          setSelected(null)
        }}
        onSaved={refresh}
      />

      {/* anulación (void) — §9.6: los egresos no se borran, se anulan */}
      <Dialog open={Boolean(voidTarget)} onOpenChange={(open) => !open && setVoidTarget(null)}>
        <DialogContent className='max-w-md p-0' aria-describedby={undefined}>
          <DialogHeader className='px-6 pt-6'>
            <DialogTitle>Anular egreso</DialogTitle>
          </DialogHeader>
          <div className='px-6 py-2'>
            <p className='mb-3 text-[13px] text-[var(--ink-2)]'>
              El egreso «{voidTarget?.concept}» de {displayMoney(voidTarget?.amount ?? 0)} quedará
              anulado y el saldo de la cuenta se restaurará. La operación queda en auditoría.
            </p>
            <Textarea
              rows={3}
              value={voidReason}
              onChange={(e) => setVoidReason(e.target.value)}
              placeholder='Motivo de la anulación (obligatorio)…'
              className='resize-none'
            />
          </div>
          <DialogFooter className='px-6 pb-6'>
            <Button
              variant='outline'
              onClick={() => setVoidTarget(null)}
              disabled={voidMutation.isPending}
            >
              Volver
            </Button>
            <Button
              variant='destructive'
              disabled={voidMutation.isPending || voidReason.trim().length === 0}
              onClick={() =>
                voidTarget &&
                voidMutation.mutate({ expense: voidTarget, reason: voidReason.trim() })
              }
            >
              {voidMutation.isPending ? 'Anulando…' : 'Anular egreso'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
