import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import type { IComplaintSettings } from '@/modules/complaint/schema'
import { useSectionEdits } from '@/modules/configuration/hooks/useSectionEdits'
import { apiClient } from '@/shared/api/apiClient'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CircleHelp } from 'lucide-react'

const CARD = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'
const COMPLAINT_SETTINGS_PATH = '/config/complaint-settings'

/** Role keys elegibles como gestores/registradores (design ADR-5: directiva + administrador). */
const ROLE_OPTIONS: ReadonlyArray<{ key: string; label: string }> = [
  { key: 'president', label: 'Presidente' },
  { key: 'treasurer', label: 'Tesorero' },
  { key: 'secretary', label: 'Secretario' },
  { key: 'vocal', label: 'Vocal' },
  { key: 'oversight_committee', label: 'Comité de vigilancia' },
  { key: 'administrator', label: 'Administrador' },
]

async function getComplaintSettings(): Promise<IComplaintSettings> {
  const { data } = await apiClient.get<{ data: IComplaintSettings }>(COMPLAINT_SETTINGS_PATH)
  return data.data
}

async function saveComplaintSettings(payload: Record<string, unknown>): Promise<unknown> {
  const { data } = await apiClient.put(COMPLAINT_SETTINGS_PATH, payload)
  return data
}

/** Drops keys whose edit value is `undefined` (e.g. the days field cleared by
 *  the user) before PUT — explicit instead of relying on JSON.stringify's
 *  implicit undefined-key omission. */
function buildSavePayload(edits: Partial<IComplaintSettings>): Record<string, unknown> {
  const payload: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(edits)) {
    if (value !== undefined) payload[key] = value
  }
  return payload
}

interface Props {
  canUpdate: boolean
}

/**
 * Configuración de incidencias (complaints design §10): 5 slugs almacenados en
 * Setting, GET/PUT /config/complaint-settings. Espeja PackageConfigSection.
 *
 * Slugs (flat):
 *   manager_role_keys              json  — roles que gestionan incidencias
 *   reporter_role_keys             json  — roles que pueden registrar incidencias
 *   auto_notify_managers_on_create bool  — avisar a gestores al registrar
 *   requires_evidence              bool  — exigir evidencia al registrar
 *   resolution_days_target         int   — días objetivo de resolución (indicador)
 *   post_to_reports_channel        bool  — publicar aviso mínimo en el canal Reportes y sugerencias
 */
export function ComplaintConfigSection({ canUpdate }: Props) {
  const queryClient = useQueryClient()

  const {
    data: settings,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['config', 'complaint-settings'],
    queryFn: getComplaintSettings,
  })

  const { form, edits, setField } = useSectionEdits<IComplaintSettings>(settings)

  const saveMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) => saveComplaintSettings(payload),
    onSuccess: () => {
      toast.success('Configuración de incidencias guardada')
      void queryClient.invalidateQueries({ queryKey: ['config', 'complaint-settings'] })
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

  if (isError) {
    return (
      <div className={CARD}>
        <p className='py-6 text-center text-[13px] text-destructive'>
          No se pudo cargar la configuración de incidencias.
        </p>
        <div className='flex justify-center pb-2'>
          <Button variant='outline' size='sm' onClick={() => refetch()}>
            Reintentar
          </Button>
        </div>
      </div>
    )
  }

  const roleMultiselect = (
    key: 'manager_role_keys' | 'reporter_role_keys',
    label: string,
    description: string
  ) => {
    const selected = Array.isArray(form[key]) ? form[key] : []

    const toggleRole = (roleKey: string, checked: boolean) => {
      const next = checked ? [...selected, roleKey] : selected.filter((k) => k !== roleKey)
      setField(key, next)
    }

    return (
      <Field>
        <FieldLabel>{label}</FieldLabel>
        <div className='flex flex-wrap gap-x-5 gap-y-2'>
          {ROLE_OPTIONS.map((role) => {
            const isChecked = selected.includes(role.key)
            return (
              <label key={role.key} className='flex cursor-pointer items-center gap-2 text-sm'>
                <Checkbox
                  checked={isChecked}
                  onCheckedChange={(checked) => toggleRole(role.key, checked === true)}
                  disabled={
                    !canUpdate ||
                    isPending ||
                    // No permitir vaciar la lista: el backend exige al menos un rol.
                    (isChecked && selected.length === 1)
                  }
                />
                {role.label}
              </label>
            )
          })}
        </div>
        <FieldDescription>{description}</FieldDescription>
      </Field>
    )
  }

  const boolSetting = (
    key: 'auto_notify_managers_on_create' | 'requires_evidence' | 'post_to_reports_channel',
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
        {/* Roles */}
        <div>
          <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Roles
          </p>
          <div className='space-y-5'>
            {roleMultiselect(
              'manager_role_keys',
              'Roles que gestionan incidencias',
              'Estos roles pueden atender, asignar y cambiar el estado de las incidencias. Quien viva en la unidad señalada queda excluido automáticamente.'
            )}
            {roleMultiselect(
              'reporter_role_keys',
              'Roles que pueden registrar incidencias',
              'Estos roles pueden levantar nuevas incidencias en el condominio.'
            )}
          </div>
        </div>

        {/* Registro */}
        <div>
          <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Registro
          </p>
          <div className='space-y-4'>
            {boolSetting(
              'auto_notify_managers_on_create',
              'Avisar a gestores al registrar una incidencia',
              'Envía una notificación a los roles gestores en cuanto se registra una nueva incidencia.'
            )}
            {boolSetting(
              'requires_evidence',
              'Exigir evidencia al registrar',
              'Obliga a adjuntar al menos un archivo (foto o documento) para poder registrar la incidencia.'
            )}
            {boolSetting(
              'post_to_reports_channel',
              'Publicar aviso en el canal Reportes y sugerencias',
              'Publica un aviso (folio y categoría, sin detalles) en el canal Reportes y sugerencias al registrar una incidencia. Es visible para toda la comunidad, pero el aviso no incluye detalles del caso.'
            )}
          </div>
        </div>

        {/* Seguimiento */}
        <div>
          <p className='mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
            Seguimiento
          </p>
          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-12 md:col-span-6'>
              <FieldLabel
                htmlFor='complaint-cfg-resolution-days'
                className='flex items-center gap-1.5'
              >
                Días objetivo de resolución
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      type='button'
                      aria-label='Ayuda: Días objetivo de resolución'
                      className='text-[var(--ink-3)] hover:text-foreground'
                    >
                      <CircleHelp className='size-3.5' aria-hidden />
                    </button>
                  </TooltipTrigger>
                  <TooltipContent className='max-w-[260px]'>
                    Meta de días para resolver una incidencia. Solo se usa como indicador visual de
                    antigüedad; no dispara acciones automáticas.
                  </TooltipContent>
                </Tooltip>
              </FieldLabel>
              <Input
                id='complaint-cfg-resolution-days'
                type='number'
                min={1}
                max={365}
                value={
                  form.resolution_days_target != null ? String(form.resolution_days_target) : ''
                }
                onChange={(e) =>
                  setField(
                    'resolution_days_target',
                    e.target.value === '' ? undefined : Number(e.target.value)
                  )
                }
                disabled={!canUpdate || isPending}
              />
            </Field>
          </div>
        </div>

        {canUpdate && (
          <div className='flex justify-end border-t border-[var(--line-2)] pt-4'>
            <Button
              onClick={() => saveMutation.mutate(buildSavePayload(edits))}
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
