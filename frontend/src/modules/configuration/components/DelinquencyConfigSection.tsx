import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { useSectionEdits } from '@/modules/configuration/hooks/useSectionEdits'
import { apiClient, ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import {
  minorUnitsToPesos,
  moneyToMinorUnits,
  pesosToMinorUnits,
  type IMoney,
} from '@/shared/utils/money'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo } from 'react'

const CARD = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'
const DELINQUENCY_SETTINGS_PATH = '/config/delinquency-settings'

interface DelinquencySettingsResponse {
  restrictions_enabled: boolean
  min_overdue_amount: IMoney
  block_package_intake: boolean
  block_amenities: boolean
  block_visitor_access: boolean
  notice_text: string
}

/**
 * Form-shape mirror of `DelinquencySettingsResponse`: the money field is edited
 * in PESOS (numeric input) — `min_overdue_amount` (minor units, TenantMoney on
 * GET) is translated to `min_overdue_amount_pesos` once at load, and back to
 * minor units only at save time. Every other key is a passthrough.
 */
interface DelinquencyForm {
  restrictions_enabled: boolean
  min_overdue_amount_pesos: number
  block_package_intake: boolean
  block_amenities: boolean
  block_visitor_access: boolean
  notice_text: string
}

async function getDelinquencySettings(): Promise<DelinquencySettingsResponse> {
  const { data } = await apiClient.get<{ data: DelinquencySettingsResponse }>(
    DELINQUENCY_SETTINGS_PATH
  )
  return data.data
}

async function saveDelinquencySettings(payload: Record<string, unknown>): Promise<unknown> {
  const { data } = await apiClient.put(DELINQUENCY_SETTINGS_PATH, payload)
  return data
}

interface Props {
  canUpdate: boolean
}

/**
 * Configuración de restricciones por morosidad (design §8.1): 6 slugs
 * almacenados en Setting, GET/PUT /config/delinquency-settings. Espeja el
 * patrón de PackageConfigSection (autocontenida, React Query + useSectionEdits,
 * PUT envía solo el diff).
 *
 * Slugs (flat, wire keys):
 *   restrictions_enabled    bool    — interruptor general
 *   min_overdue_amount      int     — adeudo mínimo vencido, minor units (TenantMoney en GET)
 *   block_package_intake    bool    — bloquea recepción de paquetes
 *   block_amenities         bool    — bloquea creación de reservaciones
 *   block_visitor_access    bool    — bloquea generación/autorización de accesos de visita
 *   notice_text             string  — aviso mostrado al residente
 */
export function DelinquencyConfigSection({ canUpdate }: Props) {
  const queryClient = useQueryClient()

  const { data: settings } = useQuery({
    queryKey: ['config', 'delinquency-settings'],
    queryFn: getDelinquencySettings,
  })

  const serverForm = useMemo<DelinquencyForm | undefined>(() => {
    if (!settings) return undefined
    return {
      restrictions_enabled: settings.restrictions_enabled,
      min_overdue_amount_pesos: minorUnitsToPesos(moneyToMinorUnits(settings.min_overdue_amount)),
      block_package_intake: settings.block_package_intake,
      block_amenities: settings.block_amenities,
      block_visitor_access: settings.block_visitor_access,
      notice_text: settings.notice_text,
    }
  }, [settings])

  const { form, edits, setField } = useSectionEdits<DelinquencyForm>(serverForm)

  const saveMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) => saveDelinquencySettings(payload),
    onSuccess: () => {
      toast.success('Configuración de morosidad guardada')
      void queryClient.invalidateQueries({ queryKey: ['config', 'delinquency-settings'] })
    },
    onError: (error: unknown) => {
      if (error instanceof ApiError) {
        const { title } = formatApiErrorMessage(error)
        toast.error(title)
      } else {
        toast.error('No se pudo guardar')
      }
    },
  })

  const isPending = saveMutation.isPending

  function handleSave() {
    // Diff-only PUT: only the keys the admin actually touched, converting the
    // money field back to minor units at the wire boundary.
    const payload: Record<string, unknown> = {}
    for (const [key, value] of Object.entries(edits)) {
      if (key === 'min_overdue_amount_pesos') {
        payload.min_overdue_amount = pesosToMinorUnits(value as number)
      } else {
        payload[key] = value
      }
    }
    saveMutation.mutate(payload)
  }

  const boolSetting = (
    key:
      | 'restrictions_enabled'
      | 'block_package_intake'
      | 'block_amenities'
      | 'block_visitor_access',
    label: string,
    description: string
  ) => (
    <Field>
      <label className='flex cursor-pointer items-center gap-2 text-sm font-medium'>
        <Checkbox
          checked={Boolean(form[key])}
          onCheckedChange={(checked) => setField(key, checked === true)}
          disabled={!canUpdate || isPending}
        />
        {label}
      </label>
      <FieldDescription>{description}</FieldDescription>
    </Field>
  )

  return (
    <div className={CARD}>
      <FieldGroup className='gap-6'>
        {/* Interruptor general */}
        <div>
          <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Interruptor general
          </p>
          {boolSetting(
            'restrictions_enabled',
            'Activar restricciones por morosidad',
            'Cuando está desactivado, ninguna unidad se restringe sin importar su adeudo.'
          )}
        </div>

        {/* Umbral de adeudo */}
        <div>
          <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Umbral de adeudo
          </p>
          <Field className='col-span-12 md:col-span-6'>
            <FieldLabel htmlFor='delq-cfg-min-amount'>Adeudo mínimo vencido (MXN)</FieldLabel>
            <Input
              id='delq-cfg-min-amount'
              type='number'
              step='0.01'
              min='0'
              value={
                form.min_overdue_amount_pesos != null ? String(form.min_overdue_amount_pesos) : ''
              }
              onChange={(e) =>
                setField(
                  'min_overdue_amount_pesos',
                  e.target.value === '' ? 0 : Number(e.target.value)
                )
              }
              disabled={!canUpdate || isPending}
            />
            <FieldDescription>
              A partir de este monto vencido se restringen las funciones seleccionadas. Déjalo en 0
              para restringir cualquier unidad con vencidos.
            </FieldDescription>
          </Field>
        </div>

        {/* Funciones restringidas */}
        <div>
          <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Funciones restringidas
          </p>
          <div className='space-y-4'>
            {boolSetting(
              'block_amenities',
              'Reservaciones de amenidades',
              'Impide crear nuevas reservaciones de amenidades para unidades con adeudos vencidos.'
            )}
            {boolSetting(
              'block_visitor_access',
              'Accesos de visita',
              'Impide generar o autorizar códigos de acceso para visitas de unidades con adeudos vencidos.'
            )}
            {boolSetting(
              'block_package_intake',
              'Recepción de paquetes',
              'Impide registrar nuevos paquetes para unidades con adeudos vencidos.'
            )}
          </div>
        </div>

        {/* Aviso al residente */}
        <div>
          <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Aviso al residente
          </p>
          <Field>
            <FieldLabel htmlFor='delq-cfg-notice'>Texto del aviso</FieldLabel>
            <Textarea
              id='delq-cfg-notice'
              rows={3}
              maxLength={500}
              value={form.notice_text ?? ''}
              onChange={(e) => setField('notice_text', e.target.value)}
              disabled={!canUpdate || isPending}
            />
            <FieldDescription>
              Mensaje que ve el residente cuando una función está restringida por adeudos (máx. 500
              caracteres). No aplica a portería: ese aviso es fijo y no expone montos.
            </FieldDescription>
          </Field>
        </div>

        {canUpdate && (
          <div className='flex justify-end border-t border-[var(--line-2)] pt-4'>
            <Button
              onClick={handleSave}
              disabled={isPending}
              className='min-w-[120px] rounded-full'
            >
              {isPending ? 'Guardando…' : 'Guardar'}
            </Button>
          </div>
        )}
      </FieldGroup>
    </div>
  )
}
