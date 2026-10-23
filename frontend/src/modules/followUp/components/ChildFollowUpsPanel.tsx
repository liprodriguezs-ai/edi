import type { IFollowUp } from '@/modules/followUp/schema'
import { followUpStatusTone, followUpTypeLabel, isOpenFollowUp } from '@/modules/followUp/utils/statusCopy'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { Panel } from '@/shared/components/molecules/Panel'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Link } from 'react-router-dom'

/** Open ones first (overdue, then by due date), then the closed ones, newest first. */
function byUrgency(a: IFollowUp, b: IFollowUp): number {
  const openA = isOpenFollowUp(a)
  const openB = isOpenFollowUp(b)
  if (openA !== openB) return openA ? -1 : 1
  if (openA) return a.due_date.localeCompare(b.due_date)
  return b.due_date.localeCompare(a.due_date)
}

interface ChildFollowUpsPanelProps {
  followUps: IFollowUp[]
  isLoading: boolean
}

/**
 * "Seguimientos" tab of the child record: every follow-up of the child, the
 * ones still to do first. Referrals and changes live in each follow-up's page.
 */
export function ChildFollowUpsPanel({ followUps, isLoading }: ChildFollowUpsPanelProps) {
  const sorted = [...followUps].sort(byUrgency)

  return (
    <Panel padding='none' title='Seguimientos'>
      {isLoading ? (
        <InlineLoading name='child-follow-ups' className='px-4 py-3' />
      ) : sorted.length === 0 ? (
        <InlineEmpty className='px-4 py-3'>Este niño no tiene seguimientos.</InlineEmpty>
      ) : (
        <ul className='divide-y divide-border'>
          {sorted.map((followUp) => (
            <li key={followUp.id} className='flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3'>
              <Link
                to={`/follow-up/${followUp.id}`}
                className='min-w-52 flex-1 basis-52 font-semibold text-foreground underline-offset-4 hover:underline'
              >
                {followUpTypeLabel(followUp.follow_up_type?.key)}
              </Link>
              <span className='min-w-36 text-sm text-muted-foreground'>
                Vence {displayFormatDate(followUp.due_date, 'mediumDate')}
              </span>
              <StatusPill tone={followUpStatusTone(followUp.follow_up_status?.key)}>
                {followUp.follow_up_status?.name ?? '—'}
              </StatusPill>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  )
}
