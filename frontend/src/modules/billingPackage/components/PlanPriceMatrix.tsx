import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import type {
  IBillingPackage,
  IPlanPriceMatrix,
  IPlanPriceMatrixModule,
  IPlanPriceMatrixModuleInput,
} from '@/modules/billingPackage/schema'
import { PlanPriceMatrixResource } from '@/modules/billingPackage/services/BillingPackageResource'
import { Modal } from '@/shared/components/Modal'
import { Callout } from '@/shared/components/atoms/Callout'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { toast } from '@/shared/hooks/useToast'
import { CheckCircle2, Clock, Info } from 'lucide-react'
import { useCallback, useEffect, useReducer, useState } from 'react'

// ─── Tipos internos del estado editable ──────────────────────────────────────

interface EditableCell {
  /** Monto en PESOS (texto para el input, permite decimales durante la edición) */
  unit_amount_pesos: string
}

type EditableCellsMap = Record<string, EditableCell>

interface EditorState {
  cells: EditableCellsMap
  /**
   * "Sin costo" y los días de prueba viven a nivel MÓDULO, no por intervalo:
   * un valor distinto entre mensual y anual es un estado inválido (el backend
   * lo rechaza, y el trial solo lee una fila por módulo). Un control por módulo
   * escribe el mismo valor en TODAS sus celdas al guardar.
   */
  freeByModule: Record<string, boolean>
  /** '' = sin override: aplica el default de plataforma (default_trial_days) */
  trialByModule: Record<string, string>
}

/** Clave de celda: "{billable_module_id}|{interval}" */
function cellKey(moduleId: string, interval: string): string {
  return `${moduleId}|${interval}`
}

/** Convierte el estado editable a centavos para el PUT */
function editableToCents(cell: EditableCell): number {
  const pesos = parseFloat(cell.unit_amount_pesos)
  return isNaN(pesos) ? 0 : Math.round(pesos * 100)
}

// ─── Reducer del estado del editor ───────────────────────────────────────────

type EditorAction =
  | { type: 'INIT'; payload: EditorState }
  | { type: 'SET_AMOUNT'; key: string; value: string }
  | { type: 'SET_FREE'; moduleId: string; value: boolean }
  | { type: 'SET_TRIAL'; moduleId: string; value: string }

function editorReducer(state: EditorState, action: EditorAction): EditorState {
  switch (action.type) {
    case 'INIT':
      return action.payload
    case 'SET_AMOUNT':
      return {
        ...state,
        cells: {
          ...state.cells,
          [action.key]: { ...state.cells[action.key], unit_amount_pesos: action.value },
        },
      }
    case 'SET_FREE':
      return {
        ...state,
        freeByModule: { ...state.freeByModule, [action.moduleId]: action.value },
      }
    case 'SET_TRIAL':
      return {
        ...state,
        trialByModule: { ...state.trialByModule, [action.moduleId]: action.value },
      }
    default:
      return state
  }
}

const EMPTY_EDITOR: EditorState = { cells: {}, freeByModule: {}, trialByModule: {} }

// ─── Inicializa el estado del editor a partir de la respuesta de la API ──────

function buildInitialState(matrix: IPlanPriceMatrix): EditorState {
  const cells: EditableCellsMap = {}
  const freeByModule: Record<string, boolean> = {}
  const trialByModule: Record<string, string> = {}

  for (const mod of matrix.modules) {
    freeByModule[mod.billable_module_id] = mod.is_free
    trialByModule[mod.billable_module_id] = mod.trial_days != null ? String(mod.trial_days) : ''

    for (const interval of matrix.intervals) {
      const key = cellKey(mod.billable_module_id, interval)
      const cell = mod.cells[interval as 'month' | 'year']
      cells[key] = { unit_amount_pesos: cell ? (cell.unit_amount / 100).toFixed(2) : '0.00' }
    }
  }

  return { cells, freeByModule, trialByModule }
}

// ─── Controles por módulo: "Sin costo" + días de prueba ──────────────────────

interface ModuleControlsProps {
  moduleId: string
  moduleName: string
  isFree: boolean
  trialDays: string
  defaultTrialDays: number
  disabled: boolean
  /** Hints largos solo en la vista de un módulo (fuera de la tabla) */
  showHints: boolean
  onFree: (moduleId: string, value: boolean) => void
  onTrial: (moduleId: string, value: string) => void
}

function ModuleControls({
  moduleId,
  moduleName,
  isFree,
  trialDays,
  defaultTrialDays,
  disabled,
  showHints,
  onFree,
  onTrial,
}: ModuleControlsProps) {
  return (
    <div className='space-y-2'>
      <div className='flex items-center gap-2'>
        <Switch
          id={`free-${moduleId}`}
          checked={isFree}
          onCheckedChange={(v) => onFree(moduleId, v)}
          disabled={disabled}
          aria-label={`Sin costo ${moduleName}`}
        />
        <label htmlFor={`free-${moduleId}`} className='text-xs cursor-pointer select-none'>
          Sin costo
        </label>
        {showHints && (
          <span className='text-xs text-muted-foreground'>aplica a mensual y anual</span>
        )}
      </div>
      <div className='flex items-center gap-2'>
        <Input
          id={`trial-${moduleId}`}
          type='number'
          min={0}
          max={730}
          value={trialDays}
          onChange={(e) => onTrial(moduleId, e.target.value)}
          disabled={disabled}
          className='h-8 text-sm w-16'
          placeholder={String(defaultTrialDays)}
          title={`Vacío = predeterminado de la plataforma (${defaultTrialDays} días)`}
          aria-label={`Días de prueba ${moduleName}`}
        />
        <label
          htmlFor={`trial-${moduleId}`}
          className='text-xs text-muted-foreground cursor-pointer select-none'
        >
          días de prueba
        </label>
        {showHints && (
          <span className='text-xs text-muted-foreground'>
            vacío = predeterminado ({defaultTrialDays} días)
          </span>
        )}
      </div>
    </div>
  )
}

// ─── Componente de fila de módulo en la tabla ─────────────────────────────────

interface ModuleRowProps {
  mod: IPlanPriceMatrixModule
  intervals: string[]
  cells: EditableCellsMap
  isFree: boolean
  trialDays: string
  defaultTrialDays: number
  isSaving: boolean
  /**
   * Con un solo módulo facturable (el plan base) la columna "Módulo" no aporta
   * nada: la grilla queda como "precio del plan por intervalo" y se oculta.
   */
  showModuleColumn: boolean
  onAmount: (key: string, value: string) => void
  onFree: (moduleId: string, value: boolean) => void
  onTrial: (moduleId: string, value: string) => void
}

const INTERVAL_LABELS: Record<string, string> = {
  month: 'Mensual',
  year: 'Anual',
}

function StripeIndicator({ stripeId }: { stripeId: string | null | undefined }) {
  if (stripeId) {
    return (
      <span
        className='inline-flex items-center gap-1 text-[11px] text-primary'
        title={`Ya existe como precio en Stripe y se puede cobrar (${stripeId})`}
      >
        <CheckCircle2 className='h-3 w-3' />
        Sincronizado
      </span>
    )
  }
  return (
    <span
      className='inline-flex items-center gap-1 text-[11px] text-muted-foreground'
      title='Este precio aún no existe en Stripe: se crea al correr billing:sync-stripe. Hasta entonces no se puede cobrar con tarjeta.'
    >
      <Clock className='h-3 w-3' />
      Pendiente
    </span>
  )
}

function ModuleRow({
  mod,
  intervals,
  cells,
  isFree,
  trialDays,
  defaultTrialDays,
  isSaving,
  showModuleColumn,
  onAmount,
  onFree,
  onTrial,
}: ModuleRowProps) {
  return (
    <TableRow>
      {showModuleColumn && (
        <TableCell className='font-medium align-top'>
          <div className='text-sm'>{mod.name}</div>
          {mod.is_base && <span className='text-[11px] text-muted-foreground'>Base</span>}
          <div className='mt-2'>
            <ModuleControls
              moduleId={mod.billable_module_id}
              moduleName={mod.name}
              isFree={isFree}
              trialDays={trialDays}
              defaultTrialDays={defaultTrialDays}
              disabled={isSaving}
              showHints={false}
              onFree={onFree}
              onTrial={onTrial}
            />
          </div>
        </TableCell>
      )}
      {intervals.map((interval) => {
        const key = cellKey(mod.billable_module_id, interval)
        const cell = cells[key]
        const originalCell = mod.cells[interval as 'month' | 'year']
        if (!cell)
          return (
            <TableCell key={interval} className='text-muted-foreground text-sm'>
              —
            </TableCell>
          )
        return (
          <TableCell key={interval} className='align-top'>
            <div className='space-y-2 min-w-[160px]'>
              {/* Monto en pesos */}
              <div className='flex items-center gap-2'>
                <span className='text-muted-foreground text-sm shrink-0'>$</span>
                <Input
                  type='number'
                  min={0}
                  step='0.01'
                  value={cell.unit_amount_pesos}
                  onChange={(e) => onAmount(key, e.target.value)}
                  disabled={isSaving || isFree}
                  className='h-8 font-mono text-sm tabular-nums'
                  placeholder='0.00'
                  aria-label={`Monto ${mod.name} ${INTERVAL_LABELS[interval] ?? interval}`}
                />
                <span className='text-muted-foreground text-xs shrink-0'>MXN</span>
              </div>

              {/* Indicador de sincronización con Stripe */}
              <StripeIndicator stripeId={originalCell?.stripe_price_id} />
            </div>
          </TableCell>
        )
      })}
    </TableRow>
  )
}

// ─── Modal principal ──────────────────────────────────────────────────────────

export interface PlanPriceMatrixProps {
  isOpen: boolean
  plan: IBillingPackage | null
  onClose: () => void
}

export function PlanPriceMatrix({ isOpen, plan, onClose }: PlanPriceMatrixProps) {
  const [matrix, setMatrix] = useState<IPlanPriceMatrix | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [isSaving, setIsSaving] = useState(false)
  const [editor, dispatch] = useReducer(editorReducer, EMPTY_EDITOR)

  // Carga lazy: solo cuando se abre y hay un plan seleccionado
  useEffect(() => {
    if (!isOpen || !plan) {
      setMatrix(null)
      dispatch({ type: 'INIT', payload: EMPTY_EDITOR })
      return
    }

    let cancelled = false

    setIsLoading(true)
    PlanPriceMatrixResource.get(plan.id)
      .then((data) => {
        if (cancelled) return
        setMatrix(data)
        dispatch({ type: 'INIT', payload: buildInitialState(data) })
      })
      .catch(() => {
        if (cancelled) return
        toast.error('Error al cargar los precios del plan')
      })
      .finally(() => {
        if (cancelled) return
        setIsLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [isOpen, plan])

  const handleSave = useCallback(async () => {
    if (!plan || !matrix) return

    const amountFor = (moduleId: string, interval: string): number => {
      const cell = editor.cells[cellKey(moduleId, interval)]
      return cell ? editableToCents(cell) : 0
    }

    const modulesPayload: IPlanPriceMatrixModuleInput[] = matrix.modules.map((mod) => {
      const trialRaw = (editor.trialByModule[mod.billable_module_id] ?? '').trim()
      const parsedTrial = trialRaw === '' ? NaN : parseInt(trialRaw, 10)

      return {
        billable_module_id: mod.billable_module_id,
        is_free: editor.freeByModule[mod.billable_module_id] ?? false,
        trial_days: isNaN(parsedTrial) ? null : parsedTrial,
        cells: {
          month: { unit_amount: amountFor(mod.billable_module_id, 'month'), currency: 'MXN' },
          year: { unit_amount: amountFor(mod.billable_module_id, 'year'), currency: 'MXN' },
        },
      }
    })

    setIsSaving(true)
    try {
      const updated = await PlanPriceMatrixResource.update(plan.id, modulesPayload)
      setMatrix(updated)
      dispatch({ type: 'INIT', payload: buildInitialState(updated) })
      toast.success('Precios actualizados correctamente')
      onClose()
    } catch {
      toast.error('Error al guardar los precios')
    } finally {
      setIsSaving(false)
    }
  }, [plan, matrix, editor, onClose])

  const handleAmount = useCallback((key: string, value: string) => {
    dispatch({ type: 'SET_AMOUNT', key, value })
  }, [])

  const handleFree = useCallback((moduleId: string, value: boolean) => {
    dispatch({ type: 'SET_FREE', moduleId, value })
  }, [])

  const handleTrial = useCallback((moduleId: string, value: string) => {
    dispatch({ type: 'SET_TRIAL', moduleId, value })
  }, [])

  if (!isOpen || !plan) return null

  const intervals = matrix?.intervals ?? []
  // Hoy sólo existe el módulo base: la grilla se presenta como el precio del PLAN
  // por intervalo. La estructura por módulo se conserva por si vuelve a haber varios.
  const showModuleColumn = (matrix?.modules.length ?? 0) > 1
  const singleModule = !showModuleColumn ? (matrix?.modules[0] ?? null) : null

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={`Precios del plan — ${plan.name}`}
      size='xl'
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
            onClick={() => {
              void handleSave()
            }}
            disabled={isLoading || !matrix}
            loading={isSaving}
            className='min-w-[120px]'
          >
            {isSaving ? 'Guardando...' : 'Guardar precios'}
          </Button>
        </>
      }
    >
      <div className='space-y-4 pb-4'>
        <Callout icon={Info} tone='info'>
          Define cuánto cuesta este plan en cada intervalo de cobro. Los montos se ingresan en pesos
          MXN (la conversión a centavos es automática al guardar) y se sincronizan a Stripe con el
          comando <code className='font-mono font-semibold'>billing:sync-stripe</code>.
        </Callout>

        <div>
          {isLoading ? (
            <InlineLoading name='price-matrix' lines={6} />
          ) : matrix ? (
            <div className='space-y-4'>
              {singleModule && (
                <ModuleControls
                  moduleId={singleModule.billable_module_id}
                  moduleName={singleModule.name}
                  isFree={editor.freeByModule[singleModule.billable_module_id] ?? false}
                  trialDays={editor.trialByModule[singleModule.billable_module_id] ?? ''}
                  defaultTrialDays={matrix.default_trial_days}
                  disabled={isSaving}
                  showHints
                  onFree={handleFree}
                  onTrial={handleTrial}
                />
              )}
              <div className='overflow-x-auto'>
                <Table>
                  <TableHeader>
                    <TableRow>
                      {showModuleColumn && (
                        <TableHead scope='col' className='min-w-[180px]'>
                          Módulo
                        </TableHead>
                      )}
                      {intervals.map((interval) => (
                        <TableHead key={interval} scope='col' className='min-w-[200px]'>
                          {INTERVAL_LABELS[interval] ?? interval}
                        </TableHead>
                      ))}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {matrix.modules.map((mod) => (
                      <ModuleRow
                        key={mod.billable_module_id}
                        mod={mod}
                        intervals={intervals}
                        cells={editor.cells}
                        isFree={editor.freeByModule[mod.billable_module_id] ?? false}
                        trialDays={editor.trialByModule[mod.billable_module_id] ?? ''}
                        defaultTrialDays={matrix.default_trial_days}
                        isSaving={isSaving}
                        showModuleColumn={showModuleColumn}
                        onAmount={handleAmount}
                        onFree={handleFree}
                        onTrial={handleTrial}
                      />
                    ))}
                  </TableBody>
                </Table>
              </div>
            </div>
          ) : (
            <InlineEmpty className='justify-center py-8'>No se pudieron cargar los precios del plan.</InlineEmpty>
          )}
        </div>
      </div>
    </Modal>
  )
}
