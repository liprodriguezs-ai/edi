import type { IAppointment } from '@/modules/appointment/schema'
import type { IFollowUp } from '@/modules/followUp/schema'
import { followUpTypeLabel } from '@/modules/followUp/utils/statusCopy'
import type { IScreening } from '@/modules/screening/schema'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { Panel } from '@/shared/components/molecules/Panel'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { formatDateOnly, formatTenantTime, instantToTenantCivilDate } from '@/shared/utils/tenantTime'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

interface ChildSummaryCardsProps {
  childId: string
  timeZone: string
  nextAppointment: IAppointment | null
  /** Latest test that already has a result. */
  lastScreening: IScreening | null
  openFollowUps: IFollowUp[]
  isLoading: boolean
}

function Card({ eyebrow, children, link }: { eyebrow: string; children: ReactNode; link?: ReactNode }) {
  return (
    <Panel eyebrow={eyebrow} className='flex flex-col gap-2'>
      <div className='space-y-1'>{children}</div>
      {link && <div className='pt-1 text-sm'>{link}</div>}
    </Panel>
  )
}

const LINK = 'text-[var(--b)] underline-offset-4 hover:underline'

/** "Resumen" tab of the child record: what to know before attending the child. */
export function ChildSummaryCards({
  childId,
  timeZone,
  nextAppointment,
  lastScreening,
  openFollowUps,
  isLoading,
}: ChildSummaryCardsProps) {
  if (isLoading) return <InlineLoading name='child-summary' className='py-3' />

  const firstFollowUp = openFollowUps[0]

  return (
    <div className='grid grid-cols-1 gap-4 md:grid-cols-3'>
      <Card eyebrow='Próxima cita' link={<Link className={LINK} to={`/child/${childId}?tab=citas`}>Ver citas</Link>}>
        {nextAppointment ? (
          <>
            <p className='text-lg font-semibold'>
              {formatDateOnly(instantToTenantCivilDate(nextAppointment.starts_at, timeZone))} ·{' '}
              {formatTenantTime(nextAppointment.starts_at, timeZone)}
            </p>
            <p className='text-sm text-muted-foreground'>
              {nextAppointment.appointment_type?.name ?? 'Cita'} ·{' '}
              {nextAppointment.practitioner?.user?.name ?? 'Sin profesional'}
            </p>
          </>
        ) : (
          <p className='text-lg font-semibold'>Sin citas agendadas</p>
        )}
      </Card>

      <Card
        eyebrow='Última Prueba EDI'
        link={<Link className={LINK} to={`/child/${childId}?tab=pruebas`}>Ver pruebas</Link>}
      >
        {lastScreening?.result_color ? (
          <>
            <ResultColorPill colorKey={lastScreening.result_color.key} />
            <p className='text-sm text-muted-foreground'>
              {displayFormatDate(lastScreening.visit?.visit_date, 'mediumDate')}
            </p>
          </>
        ) : (
          <p className='text-lg font-semibold'>Sin Pruebas EDI calificadas</p>
        )}
      </Card>

      <Card
        eyebrow='Seguimientos abiertos'
        link={<Link className={LINK} to={`/child/${childId}?tab=seguimientos`}>Ver seguimientos</Link>}
      >
        {firstFollowUp ? (
          <>
            <p className='text-lg font-semibold'>
              {openFollowUps.length === 1 ? '1 pendiente' : `${openFollowUps.length} pendientes`}
            </p>
            <p className='text-sm text-muted-foreground'>
              <Link className={LINK} to={`/follow-up/${firstFollowUp.id}`}>
                {followUpTypeLabel(firstFollowUp.follow_up_type?.key)}
              </Link>{' '}
              · vence {formatDateOnly(firstFollowUp.due_date)}
            </p>
          </>
        ) : (
          <p className='text-lg font-semibold'>Ninguno abierto</p>
        )}
      </Card>
    </div>
  )
}
