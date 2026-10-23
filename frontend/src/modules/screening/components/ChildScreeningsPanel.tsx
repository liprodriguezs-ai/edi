import { Button } from '@/components/ui/button'
import type { IScreening } from '@/modules/screening/schema'
import { canContinueApplication, screeningStatusLabel, screeningStatusTone } from '@/modules/screening/utils/statusCopy'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { Panel } from '@/shared/components/molecules/Panel'
import { formatAgeDays } from '@/shared/utils/ageFormat'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { ClipboardCheck } from 'lucide-react'
import { Link } from 'react-router-dom'

interface ChildScreeningsPanelProps {
  screenings: IScreening[]
  isLoading: boolean
  onNew: () => void
}

/** Newest visit first; the API cannot sort screenings, so it is done here. */
function newestFirst(a: IScreening, b: IScreening): number {
  return (b.visit?.visit_date ?? '').localeCompare(a.visit?.visit_date ?? '')
}

/**
 * "Pruebas EDI" tab of the child record: every test of the child with its
 * state and result, the way back into an open capture, and "Nueva Prueba EDI".
 * Scoring, closing, verifying and annulling live in each test's own screens.
 */
export function ChildScreeningsPanel({ screenings, isLoading, onNew }: ChildScreeningsPanelProps) {
  const sorted = [...screenings].sort(newestFirst)

  return (
    <Panel
      padding='none'
      title='Pruebas EDI'
      actions={
        <PermissionGuard permission='create.screening'>
          <Button size='sm' onClick={onNew}>
            <ClipboardCheck className='size-4' aria-hidden />
            Nueva Prueba EDI
          </Button>
        </PermissionGuard>
      }
    >
      {isLoading ? (
        <InlineLoading name='child-screenings' className='px-4 py-3' />
      ) : sorted.length === 0 ? (
        <InlineEmpty className='px-4 py-3'>Este niño todavía no tiene Pruebas EDI.</InlineEmpty>
      ) : (
        <ul className='divide-y divide-border'>
          {sorted.map((item) => {
            const statusKey = item.screening_status?.key
            const open = canContinueApplication(statusKey)
            return (
              <li key={item.id} className='flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3'>
                <div className='min-w-40 flex-1 basis-40'>
                  <p className='font-semibold'>{displayFormatDate(item.visit?.visit_date, 'mediumDate')}</p>
                  <p className='font-mono text-xs text-muted-foreground'>{item.visit?.receipt_number}</p>
                </div>
                <div className='min-w-36 basis-36 text-sm'>
                  <p>{formatAgeDays(item.chronological_age_days)}</p>
                  <p className='text-muted-foreground'>{item.applied_age_group?.name ?? '—'}</p>
                </div>
                <StatusPill tone={screeningStatusTone(statusKey)}>{screeningStatusLabel(statusKey)}</StatusPill>
                <div className='min-w-28'>
                  {item.result_color ? (
                    <ResultColorPill colorKey={item.result_color.key} />
                  ) : (
                    <span className='text-sm text-muted-foreground'>Sin calificar</span>
                  )}
                </div>
                <span className='min-w-36 flex-1 basis-36 text-sm text-muted-foreground'>
                  {item.visit?.practitioner?.user?.name ?? '—'}
                </span>
                <Button asChild size='sm' variant={open ? 'default' : 'outline'} className='min-w-36'>
                  <Link to={open ? `/screening/${item.id}/apply` : `/screening/${item.id}`}>
                    {open ? 'Continuar captura' : 'Ver resultado'}
                  </Link>
                </Button>
              </li>
            )
          })}
        </ul>
      )}
    </Panel>
  )
}
