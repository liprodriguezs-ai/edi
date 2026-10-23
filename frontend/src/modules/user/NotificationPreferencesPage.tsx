import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import {
  NOTIFICATION_CATEGORIES,
  NOTIFICATION_CHANNELS,
  useNotificationPreferences,
} from '@/modules/user/hooks/useNotificationPreferences'
import type {
  NotificationChannel,
  NotificationChannelMap,
  NotificationPreferences,
} from '@/modules/user/services/UserResource'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { Panel } from '@/shared/components/molecules/Panel'
import { useNotificationSoundMuted } from '@/shared/notifications/notificationSound'
import { useState } from 'react'

/** Toggle client-side (localStorage) del sonido de notificaciones in-app. */
function SoundPreferenceCard() {
  const [muted, setMuted] = useNotificationSoundMuted()

  return (
    <Panel as="div" className="flex items-center justify-between">
      <div>
        <p className="text-[14px] font-semibold">Sonido de notificaciones</p>
        <p className="mt-0.5 text-[12.5px] text-[var(--ink-3)]">
          Reproduce un sonido al recibir un aviso mientras tenés la app abierta (preferencia de este dispositivo).
        </p>
      </div>
      <Switch
        checked={!muted}
        onCheckedChange={(on) => setMuted(!on)}
        aria-label="Activar sonido de notificaciones"
      />
    </Panel>
  )
}

type ChannelGrid = Record<string, NotificationChannelMap>

/** Construye la grilla con el default "todo activado": un canal ausente = activado. */
function buildGrid(preferences: NotificationPreferences): ChannelGrid {
  const grid: ChannelGrid = {}
  for (const category of NOTIFICATION_CATEGORIES) {
    grid[category.key] = {}
    for (const channel of NOTIFICATION_CHANNELS) {
      grid[category.key][channel.key] = preferences.channels?.[category.key]?.[channel.key] ?? true
    }
  }
  return grid
}

function PreferencesForm({ initial, saving, onSave }: {
  initial: NotificationPreferences
  saving: boolean
  onSave: (_payload: { channels: ChannelGrid; quiet_hours_start: string | null; quiet_hours_end: string | null }) => void
}) {
  const [grid, setGrid] = useState<ChannelGrid>(() => buildGrid(initial))
  const [quietStart, setQuietStart] = useState(initial.quiet_hours_start ?? '')
  const [quietEnd, setQuietEnd] = useState(initial.quiet_hours_end ?? '')

  const toggle = (category: string, channel: NotificationChannel) => {
    setGrid((prev) => ({
      ...prev,
      [category]: { ...prev[category], [channel]: !prev[category][channel] },
    }))
  }

  const save = () => {
    onSave({
      channels: grid,
      quiet_hours_start: quietStart || null,
      quiet_hours_end: quietEnd || null,
    })
  }

  return (
    <div className="space-y-6">
      <Panel as="div" padding="none" className="overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead scope="col">Categoría</TableHead>
              {NOTIFICATION_CHANNELS.map((channel) => (
                <TableHead key={channel.key} scope="col" className="text-center">{channel.label}</TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {NOTIFICATION_CATEGORIES.map((category) => (
              <TableRow key={category.key}>
                <TableCell className="font-medium">{category.label}</TableCell>
                {NOTIFICATION_CHANNELS.map((channel) => (
                  <TableCell key={channel.key} className="text-center">
                    <Checkbox
                      checked={grid[category.key][channel.key] !== false}
                      onCheckedChange={() => toggle(category.key, channel.key)}
                      disabled={saving}
                      aria-label={`${category.label} · ${channel.label}`}
                    />
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Panel>

      <Panel title="Horario de silencio" description="No recibirás notificaciones push entre estas horas (opcional).">
        <div className="flex flex-wrap items-end gap-3">
          <div className="flex flex-col">
            <label htmlFor="quiet-hours-start" className="text-[12.5px] text-[var(--ink-3)]">Desde</label>
            <Input id="quiet-hours-start" type="time" value={quietStart} onChange={(e) => setQuietStart(e.target.value)} className="mt-1 w-[140px]" disabled={saving} />
          </div>
          <div className="flex flex-col">
            <label htmlFor="quiet-hours-end" className="text-[12.5px] text-[var(--ink-3)]">Hasta</label>
            <Input id="quiet-hours-end" type="time" value={quietEnd} onChange={(e) => setQuietEnd(e.target.value)} className="mt-1 w-[140px]" disabled={saving} />
          </div>
        </div>
      </Panel>

      <div className="flex justify-end">
        <Button onClick={save} loading={saving}>
          {saving ? 'Guardando…' : 'Guardar preferencias'}
        </Button>
      </div>
    </div>
  )
}

/** Preferencias de notificación del usuario (§17.3): por categoría y canal. */
export const NotificationPreferencesPage = () => {
  const { preferences, isLoading, updateMutation } = useNotificationPreferences()

  return (
    <div className="space-y-6">
      <PageHeader title="Preferencias de notificación" description="Elige cómo y cuándo recibir avisos de la organización" />
      <SoundPreferenceCard />
      {isLoading || !preferences ? (
        <InlineLoading name="notification-preferences" lines={6} />
      ) : (
        <PreferencesForm
          initial={preferences}
          saving={updateMutation.isPending}
          onSave={(payload) => updateMutation.mutate(payload)}
        />
      )}
    </div>
  )
}
