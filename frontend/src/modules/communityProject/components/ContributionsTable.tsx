import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import type { IProjectContribution } from '@/modules/communityProject/schema'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { displayMoney, moneyToMinorUnits } from '@/shared/utils/money'
import { Link } from 'react-router-dom'

/** Acción de captura disponible en una fila según el rol del usuario. */
export type RowActionKind = 'contribute' | 'report' | null

function contributionStatusLabel(contribution: IProjectContribution): string {
  if (contribution.declined) return 'Declinada'
  return contribution.contribution_status?.name ?? '—'
}

function unitLabel(contribution: IProjectContribution): string {
  return contribution.unit?.full_identifier ?? contribution.unit?.number ?? '—'
}

/**
 * Control de captura por fila: la mesa aporta directo ("Aportar"); un residente de la
 * unidad reporta un pago a revisión ("Reportar pago"). Si ya hay un reporte pendiente,
 * se muestra el pill "Pago en revisión" en vez del botón (evita capturar dos veces).
 */
function RowActionControl({
  action,
  hasPendingReport,
  onContribute,
  onReport,
}: {
  action: RowActionKind
  hasPendingReport: boolean
  onContribute: () => void
  onReport: () => void
}) {
  if (action === 'contribute') {
    if (hasPendingReport) return <StatusPill tone='b'>Pago en revisión</StatusPill>
    return (
      <Button size='sm' className='h-7 rounded-full px-3 text-[12px]' onClick={onContribute}>
        Aportar
      </Button>
    )
  }
  if (action === 'report') {
    if (hasPendingReport) return <StatusPill tone='b'>Pago en revisión</StatusPill>
    return (
      <Button
        size='sm'
        variant='outline'
        className='h-7 rounded-full px-3 text-[12px]'
        onClick={onReport}
      >
        Reportar pago
      </Button>
    )
  }
  return null
}

interface RowActionsProps {
  contribution: IProjectContribution
  action: RowActionKind
  canDecline: boolean
  isContributable: boolean
  hasPendingReport: boolean
  isTogglingDecline: boolean
  onContribute: (_row: IProjectContribution) => void
  onReport: (_row: IProjectContribution) => void
  onToggleDecline: (_row: IProjectContribution) => void
}

/** Celda de acciones: captura (aportar/reportar/pill) + toggle declinar/revertir (solo mesa). */
function RowActions({
  contribution,
  action,
  canDecline,
  isContributable,
  hasPendingReport,
  isTogglingDecline,
  onContribute,
  onReport,
  onToggleDecline,
}: RowActionsProps) {
  return (
    <div className='flex items-center justify-end gap-2'>
      <RowActionControl
        action={action}
        hasPendingReport={hasPendingReport}
        onContribute={() => onContribute(contribution)}
        onReport={() => onReport(contribution)}
      />
      {canDecline && isContributable && (
        <Button
          variant='ghost'
          size='sm'
          className='h-7 rounded-full px-2.5 text-[12px]'
          onClick={() => onToggleDecline(contribution)}
          disabled={isTogglingDecline}
          aria-label={
            contribution.declined
              ? `Revertir declinación de ${unitLabel(contribution)}`
              : `Declinar aportación de ${unitLabel(contribution)}`
          }
        >
          {contribution.declined ? 'Revertir' : 'Declinar'}
        </Button>
      )}
    </div>
  )
}

export interface ContributionsTableProps {
  contributions: IProjectContribution[]
  showProjectColumn: boolean
  showActionsColumn: boolean
  emptyMessage: string
  canDecline: boolean
  isTogglingDecline: boolean
  pendingReportIds: Set<string>
  getRowAction: (_row: IProjectContribution) => RowActionKind
  isRowContributable: (_row: IProjectContribution) => boolean
  onContribute: (_row: IProjectContribution) => void
  onReport: (_row: IProjectContribution) => void
  onToggleDecline: (_row: IProjectContribution) => void
}

/**
 * Tabla de aportaciones por unidad (desktop) + variante apilada (mobile). Presentacional:
 * recibe filas ya resueltas y delega las acciones al orquestador. Compartida entre el
 * detalle del proyecto y la lista global (columna Proyecto opcional).
 */
export function ContributionsTable({
  contributions,
  showProjectColumn,
  showActionsColumn,
  emptyMessage,
  canDecline,
  isTogglingDecline,
  pendingReportIds,
  getRowAction,
  isRowContributable,
  onContribute,
  onReport,
  onToggleDecline,
}: ContributionsTableProps) {
  if (contributions.length === 0) {
    return (
      <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
        <p className='px-4 py-8 text-center text-[13px] text-[var(--ink-3)]'>{emptyMessage}</p>
      </div>
    )
  }

  return (
    <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
      {/* Desktop (md+): tabla shadcn */}
      <div className='hidden md:block'>
        <Table>
          <TableHeader>
            <TableRow>
              {showProjectColumn && <TableHead scope='col'>Proyecto</TableHead>}
              <TableHead scope='col'>Unidad</TableHead>
              <TableHead scope='col' className='text-right'>
                Esperado
              </TableHead>
              <TableHead scope='col' className='text-right'>
                Aportado
              </TableHead>
              <TableHead scope='col'>Estado</TableHead>
              {showActionsColumn && <TableHead scope='col' className='text-right' />}
            </TableRow>
          </TableHeader>
          <TableBody>
            {contributions.map((contribution) => {
              const contributed = moneyToMinorUnits(contribution.contributed_amount ?? 0)
              const tone = contribution.declined ? 'r' : contributed > 0 ? 'g' : 'y'
              return (
                <TableRow key={contribution.id}>
                  {showProjectColumn && (
                    <TableCell>
                      <Link
                        to={`/community-project/${contribution.community_project_id}`}
                        className='font-medium text-[var(--green-deep)] hover:underline'
                      >
                        {contribution.project?.title ?? '—'}
                      </Link>
                    </TableCell>
                  )}
                  <TableCell className='font-medium'>{unitLabel(contribution)}</TableCell>
                  <TableCell className='text-right tabular-nums'>
                    {contribution.expected_amount != null
                      ? displayMoney(contribution.expected_amount)
                      : '—'}
                  </TableCell>
                  <TableCell className='text-right tabular-nums'>
                    {displayMoney(contribution.contributed_amount ?? 0)}
                  </TableCell>
                  <TableCell>
                    <StatusPill tone={tone}>{contributionStatusLabel(contribution)}</StatusPill>
                  </TableCell>
                  {showActionsColumn && (
                    <TableCell className='text-right'>
                      <RowActions
                        contribution={contribution}
                        action={getRowAction(contribution)}
                        canDecline={canDecline}
                        isContributable={isRowContributable(contribution)}
                        hasPendingReport={pendingReportIds.has(contribution.id)}
                        isTogglingDecline={isTogglingDecline}
                        onContribute={onContribute}
                        onReport={onReport}
                        onToggleDecline={onToggleDecline}
                      />
                    </TableCell>
                  )}
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </div>

      {/* Mobile (< md): filas apiladas */}
      <ul className='md:hidden' role='list'>
        {contributions.map((contribution) => {
          const contributed = moneyToMinorUnits(contribution.contributed_amount ?? 0)
          const tone = contribution.declined ? 'r' : contributed > 0 ? 'g' : 'y'
          return (
            <li
              key={contribution.id}
              className='border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'
            >
              <div className='flex items-center justify-between gap-3'>
                <div className='min-w-0'>
                  {showProjectColumn && (
                    <Link
                      to={`/community-project/${contribution.community_project_id}`}
                      className='block truncate text-[12.5px] font-medium text-[var(--green-deep)] hover:underline'
                    >
                      {contribution.project?.title ?? '—'}
                    </Link>
                  )}
                  <span className='font-semibold text-foreground'>{unitLabel(contribution)}</span>
                </div>
                <div className='flex items-center gap-2'>
                  {showActionsColumn && (
                    <RowActions
                      contribution={contribution}
                      action={getRowAction(contribution)}
                      canDecline={canDecline}
                      isContributable={isRowContributable(contribution)}
                      hasPendingReport={pendingReportIds.has(contribution.id)}
                      isTogglingDecline={isTogglingDecline}
                      onContribute={onContribute}
                      onReport={onReport}
                      onToggleDecline={onToggleDecline}
                    />
                  )}
                  <StatusPill tone={tone}>{contributionStatusLabel(contribution)}</StatusPill>
                </div>
              </div>
              <div className='mt-2 grid grid-cols-2 gap-2 text-[12.5px]'>
                <div>
                  <div className='text-[var(--ink-3)]'>Esperado</div>
                  <div className='tabular-nums font-medium'>
                    {contribution.expected_amount != null
                      ? displayMoney(contribution.expected_amount)
                      : '—'}
                  </div>
                </div>
                <div>
                  <div className='text-[var(--ink-3)]'>Aportado</div>
                  <div className='tabular-nums font-medium'>
                    {displayMoney(contribution.contributed_amount ?? 0)}
                  </div>
                </div>
              </div>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
