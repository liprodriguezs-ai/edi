import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { useSectionEdits } from '@/modules/configuration/hooks/useSectionEdits'
import type { PackageSettings } from '@/modules/package/schema'
import { apiClient } from '@/shared/api/apiClient'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CircleHelp } from 'lucide-react'

const CARD = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'
const PACKAGE_SETTINGS_PATH = '/config/package-settings'

async function getPackageSettings(): Promise<PackageSettings> {
  const { data } = await apiClient.get<{ data: PackageSettings }>(PACKAGE_SETTINGS_PATH)
  return data.data
}

async function savePackageSettings(payload: Record<string, unknown>): Promise<unknown> {
  const { data } = await apiClient.put(PACKAGE_SETTINGS_PATH, payload)
  return data
}

interface Props {
  canUpdate: boolean
}

/**
 * Configuración de paquetería (§7): 7 slugs almacenados en Setting,
 * GET/PUT /config/package-settings. Espeja el patrón de CollectionConfigSection.
 *
 * The endpoint uses FLAT keys on the wire — the backend strips the `package.`
 * prefix on GET and only accepts flat keys on PUT (slugMap + $request->only(...)).
 *
 * Slugs (flat):
 *   auto_notify_on_arrival       bool   — notifica llegada automáticamente
 *   pickup_code_enabled          bool   — genera código de retiro
 *   requires_pickup_signature    bool   — exige firma al entregar
 *   requires_pickup_evidence     bool   — exige foto de evidencia
 *   reminder_after_days          int    — días para enviar recordatorio
 *   return_after_days            int    — días para devolver al remitente
 *   liability_disclaimer         string — texto de aviso de responsabilidad
 */
export function PackageConfigSection({ canUpdate }: Props) {
  const queryClient = useQueryClient()

  const { data: settings } = useQuery({
    queryKey: ['config', 'package-settings'],
    queryFn: getPackageSettings,
  })

  const { form, edits, setField } = useSectionEdits<PackageSettings>(settings)

  const saveMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) => savePackageSettings(payload),
    onSuccess: () => {
      toast.success('Configuración de paquetería guardada')
      void queryClient.invalidateQueries({ queryKey: ['config', 'package-settings'] })
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

  const boolSetting = (key: string, label: string, description: string) => (
    <Field>
      <label className="flex cursor-pointer items-center gap-2 text-sm font-medium">
        <Checkbox
          checked={Boolean(form[key])}
          onCheckedChange={(checked) =>
            setField(key as keyof PackageSettings, checked === true)
          }
          disabled={!canUpdate || isPending}
        />
        {label}
      </label>
      <FieldDescription>{description}</FieldDescription>
    </Field>
  )

  const numericSetting = (
    key: string,
    label: string,
    tooltip: string,
    min: number,
    max: number,
  ) => (
    <Field className="col-span-12 md:col-span-6">
      <FieldLabel htmlFor={`pkg-cfg-${key}`} className="flex items-center gap-1.5">
        {label}
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              aria-label={`Ayuda: ${label}`}
              className="text-[var(--ink-3)] hover:text-foreground"
            >
              <CircleHelp className="size-3.5" aria-hidden />
            </button>
          </TooltipTrigger>
          <TooltipContent className="max-w-[260px]">{tooltip}</TooltipContent>
        </Tooltip>
      </FieldLabel>
      <Input
        id={`pkg-cfg-${key}`}
        type="number"
        min={min}
        max={max}
        value={form[key] != null ? String(form[key]) : ''}
        onChange={(e) =>
          setField(
            key as keyof PackageSettings,
            e.target.value === '' ? undefined : Number(e.target.value),
          )
        }
        disabled={!canUpdate || isPending}
      />
    </Field>
  )

  return (
    <div className={CARD}>
      <FieldGroup className="gap-6">
        {/* Notificaciones automáticas */}
        <div>
          <p className="mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]">
            Notificaciones
          </p>
          <div className="space-y-4">
            {boolSetting(
              'auto_notify_on_arrival',
              'Notificar llegada automáticamente',
              'Envía una notificación al residente en cuanto se registra un paquete para su unidad.',
            )}
            {boolSetting(
              'pickup_code_enabled',
              'Habilitar código de retiro (QR)',
              'Genera un código único para que el residente lo muestre en portería al retirar sus paquetes.',
            )}
          </div>
        </div>

        {/* Verificación de entrega */}
        <div>
          <p className="mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]">
            Verificación de entrega
          </p>
          <div className="space-y-4">
            {boolSetting(
              'require_pickup_code',
              'Exigir verificación por código/QR al entregar',
              'Cuando está habilitado, el guardia debe escanear o ingresar el código del residente para registrar la entrega. Si está desactivado, la entrega puede registrarse directamente desde la lista.',
            )}
          </div>
        </div>

        {/* Evidencia y firma */}
        <div>
          <p className="mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]">
            Evidencia y firma
          </p>
          <div className="space-y-4">
            {boolSetting(
              'requires_pickup_signature',
              'Exigir firma al entregar',
              'El guardia debe adjuntar una foto de la firma del destinatario antes de marcar el paquete como retirado.',
            )}
            {boolSetting(
              'requires_pickup_evidence',
              'Exigir foto de evidencia',
              'El guardia debe adjuntar una foto o documento como evidencia de la entrega.',
            )}
          </div>
        </div>

        {/* Tiempos de retención */}
        <div>
          <p className="mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]">
            Tiempos de retención
          </p>
          <div className="grid grid-cols-12 gap-4">
            {numericSetting(
              'reminder_after_days',
              'Recordatorio (días)',
              'Días después de la llegada en los que se envía un recordatorio al residente si no ha retirado su paquete.',
              1,
              90,
            )}
            {numericSetting(
              'return_after_days',
              'Devolución (días)',
              'Días máximos en custodia antes de marcar el paquete para devolución al remitente.',
              1,
              365,
            )}
          </div>
        </div>

        {/* Aviso de responsabilidad */}
        <div>
          <p className="mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]">
            Aviso de responsabilidad
          </p>
          <Field>
            <FieldLabel htmlFor="pkg-cfg-liability">
              Texto de aviso de responsabilidad
            </FieldLabel>
            <Textarea
              id="pkg-cfg-liability"
              rows={3}
              maxLength={1000}
              value={form['liability_disclaimer'] != null ? String(form['liability_disclaimer']) : ''}
              onChange={(e) =>
                setField('liability_disclaimer' as keyof PackageSettings, e.target.value)
              }
              disabled={!canUpdate || isPending}
            />
            <FieldDescription>
              Texto mostrado al residente al momento del retiro (máx. 1000 caracteres).
            </FieldDescription>
          </Field>
        </div>

        {canUpdate && (
          <div className="flex justify-end border-t border-[var(--line-2)] pt-4">
            <Button
              onClick={() => saveMutation.mutate(edits as Record<string, unknown>)}
              disabled={isPending}
              className="min-w-[120px] rounded-full"
            >
              {isPending ? 'Guardando…' : 'Guardar'}
            </Button>
          </div>
        )}
      </FieldGroup>
    </div>
  )
}
