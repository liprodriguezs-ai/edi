import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import type {
  IBoardRoleOption,
  INotificationEventConfig,
  NotificationEventPatch,
} from '@/modules/configuration/services/ConfigurationResource'
import type { MultiSelectOption } from '@/shared/components/filters/MultiSelectFilter'
import { MultiSelectFilter } from '@/shared/components/filters/MultiSelectFilter'
import type { UseMutationResult } from '@tanstack/react-query'
import { useMemo, useState } from 'react'

const CARD = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'

interface Props {
  events: INotificationEventConfig[]
  boardRoles: IBoardRoleOption[]
  canUpdate: boolean
  isError?: boolean
  isLoading?: boolean
  notificationsMutation: UseMutationResult<unknown, Error, NotificationEventPatch[]>
}

type EventPatch = Partial<INotificationEventConfig>

/**
 * Pestaña "Notificaciones" en Configuración del condominio.
 *
 * Muestra cada evento de notificación con:
 *  - toggle maestro is_active
 *  - toggles de canal Email / Push / WhatsApp (según supports_*)
 *  - para eventos audience=board: multi-select de roles de mesa
 *  - para eventos audience=resident: etiqueta "Se envía al titular de la unidad"
 *
 * WhatsApp se muestra como "Próximamente" (visualmente atenuado) y está SIEMPRE
 * deshabilitado — el canal es no-op hasta que exista un proveedor configurado.
 * Usa el patrón overlay de ediciones locales (delta sobre el estado del servidor).
 */
export function NotificationsConfigSection({
  events,
  boardRoles,
  canUpdate,
  isError,
  isLoading,
  notificationsMutation,
}: Props) {
  const [edits, setEdits] = useState<Record<string, EventPatch>>({})

  const isPending = notificationsMutation.isPending

  // Overlay: server state + local edits per event key.
  const eventsForm: INotificationEventConfig[] = useMemo(
    () => events.map((ev) => ({ ...ev, ...(edits[ev.key] ?? {}) })),
    [events, edits]
  )

  const boardRoleOptions: MultiSelectOption[] = useMemo(
    () => boardRoles.map((r) => ({ value: r.id, label: r.name })),
    [boardRoles]
  )

  const patchEvent = (key: string, patch: EventPatch) => {
    setEdits((prev) => ({ ...prev, [key]: { ...(prev[key] ?? {}), ...patch } }))
  }

  const hasPendingEdits = Object.keys(edits).length > 0

  const handleSave = () => {
    const payload: NotificationEventPatch[] = eventsForm.map((ev) => ({
      key: ev.key,
      is_active: ev.is_mandatory ? true : ev.is_active,
      channel_email: ev.channel_email,
      channel_push: ev.channel_push,
      channel_whatsapp: ev.channel_whatsapp,
      board_role_ids: ev.board_role_ids,
    }))

    notificationsMutation.mutate(payload, {
      onSuccess: () => setEdits({}),
    })
  }

  if (isLoading) {
    return (
      <div className={CARD}>
        <p className='py-6 text-center text-[13px] text-[var(--ink-3)]'>
          Cargando configuración de notificaciones…
        </p>
      </div>
    )
  }

  if (isError) {
    return (
      <div className={CARD}>
        <p className='py-6 text-center text-[13px] text-destructive'>
          No se pudo cargar la configuración de notificaciones.
        </p>
      </div>
    )
  }

  if (events.length === 0) {
    return (
      <div className={CARD}>
        <p className='py-6 text-center text-[13px] text-[var(--ink-3)]'>
          Sin eventos de notificación configurados.
        </p>
      </div>
    )
  }

  return (
    <div className={CARD}>
      <div className='space-y-3'>
        {eventsForm.map((ev) => (
          <div key={ev.key} className='rounded-[12px] border border-border p-4'>
            {/* ── Fila principal: nombre + toggle maestro ── */}
            <div className='flex items-start justify-between gap-4'>
              <div className='min-w-0 flex-1'>
                <p className='text-sm font-medium leading-tight'>
                  {ev.name}
                  {ev.is_mandatory && (
                    <span className='ml-2 rounded bg-[var(--surface-2)] px-1 py-0.5 text-[10px] font-medium uppercase tracking-wide text-[var(--ink-3)]'>
                      Obligatorio
                    </span>
                  )}
                </p>
                {ev.description && (
                  <p className='mt-0.5 text-[12.5px] text-[var(--ink-3)]'>{ev.description}</p>
                )}
              </div>
              <label className='flex flex-none items-center gap-2 text-[12.5px] text-[var(--ink-3)]'>
                <Switch
                  checked={ev.is_mandatory ? true : ev.is_active}
                  onCheckedChange={(checked) => patchEvent(ev.key, { is_active: checked })}
                  disabled={ev.is_mandatory || !canUpdate || isPending}
                />
                Activo
              </label>
            </div>

            {/* ── Canales de entrega ── */}
            <div className='mt-3 flex flex-wrap items-center gap-4'>
              {ev.supports_email && (
                <label className='flex cursor-pointer items-center gap-1.5 text-[12.5px]'>
                  <Switch
                    checked={ev.channel_email}
                    onCheckedChange={(checked) => patchEvent(ev.key, { channel_email: checked })}
                    disabled={!canUpdate || isPending || !ev.is_active}
                  />
                  Email
                </label>
              )}

              {ev.supports_push && (
                <label className='flex cursor-pointer items-center gap-1.5 text-[12.5px]'>
                  <Switch
                    checked={ev.channel_push}
                    onCheckedChange={(checked) => patchEvent(ev.key, { channel_push: checked })}
                    disabled={!canUpdate || isPending || !ev.is_active}
                  />
                  Push
                </label>
              )}

              {ev.supports_whatsapp && (
                // WhatsApp is always disabled — the channel is a no-op until a
                // provider (Twilio / Meta) is configured. Rendered so the admin
                // can see it exists; aria-label conveys the "coming soon" state.
                <label className='flex items-center gap-1.5 text-[12.5px] opacity-50'>
                  <Switch
                    aria-label='Canal WhatsApp (próximamente)'
                    checked={ev.channel_whatsapp}
                    disabled
                  />
                  WhatsApp
                  <span className='rounded bg-[var(--surface-2)] px-1 py-0.5 text-[10px] font-medium uppercase tracking-wide text-[var(--ink-3)]'>
                    Próximamente
                  </span>
                </label>
              )}
            </div>

            {/* ── Destinatarios ── */}
            <div className='mt-3'>
              {ev.audience === 'board' ? (
                <MultiSelectFilter
                  label='Roles que reciben esta notificación'
                  options={boardRoleOptions}
                  value={ev.board_role_ids}
                  onChange={(next) => patchEvent(ev.key, { board_role_ids: next })}
                  compact
                />
              ) : (
                <p className='text-[12px] text-[var(--ink-3)] italic'>
                  Se envía al titular de la unidad
                </p>
              )}
            </div>
          </div>
        ))}
      </div>

      {canUpdate && (
        <div className='mt-4 flex justify-end border-t border-[var(--line-2)] pt-4'>
          <Button
            className='min-w-[120px] rounded-full'
            onClick={handleSave}
            disabled={isPending || !hasPendingEdits}
          >
            {isPending ? 'Guardando…' : 'Guardar'}
          </Button>
        </div>
      )}
    </div>
  )
}
