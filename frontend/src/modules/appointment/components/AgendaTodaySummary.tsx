import type { IAppointment } from '@/modules/appointment/schema'
import { todaySummary } from '@/modules/appointment/utils/agendaView'
import { KpiTile } from '@/shared/components/atoms/KpiTile'

interface AgendaTodaySummaryProps {
  /** Citas de hoy (todas las del día, el resumen descarta las canceladas). */
  appointments: IAppointment[]
  isLoading: boolean
}

/** Lo que hay hoy de un vistazo, sea cual sea el día o la vista que se esté viendo. */
export function AgendaTodaySummary({ appointments, isLoading }: AgendaTodaySummaryProps) {
  const summary = todaySummary(appointments)
  const value = (count: number) => (isLoading ? '—' : count)

  return (
    <section aria-label="Resumen de hoy" className="grid grid-cols-2 gap-px border border-border bg-border lg:grid-cols-4">
      <KpiTile label="Citas de hoy" value={value(summary.total)} />
      <KpiTile label="Atendidas" value={value(summary.fulfilled)} />
      <KpiTile label="En sala de espera" value={value(summary.waiting)} swatch="var(--b)" />
      <KpiTile label="Pruebas EDI por aplicar" value={value(summary.pendingScreenings)} />
    </section>
  )
}
