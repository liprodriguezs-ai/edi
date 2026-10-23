import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { childFullName } from '@/modules/child/schema'
import { AppliedGroupSummary } from '@/modules/screening/components/AppliedGroupSummary'
import { ConfirmatoryTestSection } from '@/modules/screening/components/ConfirmatoryTestSection'
import { FindingsList } from '@/modules/screening/components/FindingsList'
import { GlobalResultCard } from '@/modules/screening/components/GlobalResultCard'
import { CloseScreeningAction } from '@/modules/screening/components/CloseScreeningAction'
import { InvalidateModal } from '@/modules/screening/components/InvalidateModal'
import { PreviousComparison } from '@/modules/screening/components/PreviousComparison'
import { RescoreModal } from '@/modules/screening/components/RescoreModal'
import { ScreeningMoreActions } from '@/modules/screening/components/ScreeningMoreActions'
import { ScreeningSteps } from '@/modules/screening/components/ScreeningSteps'
import { ScreeningTimeline } from '@/modules/screening/components/ScreeningTimeline'
import { SemaphoreTable } from '@/modules/screening/components/SemaphoreTable'
import { ScreeningNutritionalStatus } from '@/modules/nutrition/components/ScreeningNutritionalStatus'
import { useScreeningDetail } from '@/modules/screening/hooks/useScreeningDetail'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { screeningStatusLabel, screeningStatusTone, screeningStepIndex } from '@/modules/screening/utils/statusCopy'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { NextAppointmentButton } from '@/modules/appointment/components/NextAppointmentButton'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Skeleton } from 'boneyard-js/react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { formatAgeDays } from '@/shared/utils/ageFormat'

interface ScreeningDetailProps {
  screeningId: string
}

export function ScreeningDetail({ screeningId }: ScreeningDetailProps) {
  const navigate = useNavigate()
  const { screening, isLoading, isError, notFound, invalidate } = useScreeningDetail(screeningId)

  const [rescoreOpen, setRescoreOpen] = useState(false)
  const [invalidateOpen, setInvalidateOpen] = useState(false)
  const [correctConfirmOpen, setCorrectConfirmOpen] = useState(false)

  /**
   * The sheet handed to the caregiver. Server-rendered from stored values, so the
   * client only moves bytes -- it never re-derives a colour or a count.
   *
   * Declared ABOVE the early returns: hooks must run in the same order on every
   * render, and the loading pass returns before this point. Hence the null check
   * lives inside the callback rather than in its placement.
   */
  const handleDownloadDocument = useCallback(async () => {
    if (!screening) return

    try {
      const blob = await ScreeningResource.document(screening.id)
      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `prueba-edi-${screening.visit?.receipt_number ?? screening.id}.pdf`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
    } catch {
      toast.error('No se pudo descargar el documento, inténtalo más tarde')
    }
  }, [screening])

  if (isError && !isLoading) {
    return (
      <EntityNotFound
        variant={notFound ? 'notFound' : 'error'}
        title={notFound ? 'Prueba EDI no encontrada' : undefined}
        backTo='/screening'
        backLabel='Volver a Prueba EDI'
      />
    )
  }

  if (isLoading || !screening) {
    return (
      <Skeleton name='screening-detail' loading>
        <div className='space-y-6'>
          <div className='h-8 w-64 rounded bg-muted' />
          <div className='h-40 rounded bg-muted' />
        </div>
      </Skeleton>
    )
  }

  const statusKey = screening.screening_status?.key
  const canPrintDocument = statusKey === 'scored' || statusKey === 'completed'
  const step = screeningStepIndex(statusKey)
  const applyPath = `/screening/${screening.id}/apply`

  return (
    <div className='space-y-6'>
      <div className='space-y-3'>
        <PageHeader
          back={{ to: `/child/${screening.child_id}?tab=pruebas`, label: 'Volver al expediente' }}
          title={screening.child ? childFullName(screening.child) : 'Prueba EDI'}
          description={
            <>
              Visita {screening.visit?.receipt_number} ·{' '}
              {displayFormatDate(screening.visit?.visit_date, 'mediumDate')}
              {screening.visit?.health_center?.name && ` · ${screening.visit.health_center.name}`}
            </>
          }
          meta={
            <>
              <StatusPill tone={screeningStatusTone(statusKey)}>{screeningStatusLabel(statusKey)}</StatusPill>
              <span className='text-xs'>
                Edad: {formatAgeDays(screening.chronological_age_days)}
                {screening.corrected_age_days != null &&
                  ` · corregida: ${formatAgeDays(screening.corrected_age_days)}`}
              </span>
            </>
          }
          actions={
            <>
              {canPrintDocument && (
                <Button variant='outline' onClick={() => void handleDownloadDocument()}>
                  Descargar documento
                </Button>
              )}
              {statusKey === 'draft' && <Button onClick={() => navigate(applyPath)}>Continuar captura</Button>}
              {statusKey === 'scored' && (
                <>
                  <Button variant='outline' onClick={() => setCorrectConfirmOpen(true)}>
                    Corregir respuestas
                  </Button>
                  <CloseScreeningAction screeningId={screening.id} child={screening.child} onDone={invalidate} />
                </>
              )}
              {statusKey === 'completed' && screening.child && (
                <NextAppointmentButton child={screening.child} />
              )}
              <ScreeningMoreActions
                statusKey={statusKey}
                onVerify={() => setRescoreOpen(true)}
                onAnnul={() => setInvalidateOpen(true)}
              />
            </>
          }
        />
        {step !== null && <ScreeningSteps current={step} />}
        <AppliedGroupSummary screening={screening} />
      </div>

      <div className='grid grid-cols-1 gap-6 lg:grid-cols-2'>
        <GlobalResultCard screening={screening} />

        <Card>
          <CardHeader>
            <CardTitle className='text-base'>Comparación con la Prueba EDI anterior</CardTitle>
          </CardHeader>
          <CardContent>
            <PreviousComparison screening={screening} />
          </CardContent>
        </Card>
      </div>

      <PermissionGuard permission='read.nutritional_assessment'>
        <Card>
          <CardHeader>
            <CardTitle className='text-base'>Estado nutricional</CardTitle>
          </CardHeader>
          <CardContent>
            <ScreeningNutritionalStatus
              screeningId={screening.id}
              measuresKey={`${screening.weight_grams ?? ''}-${screening.height_mm ?? ''}`}
            />
          </CardContent>
        </Card>
      </PermissionGuard>

      <Card>
        <CardHeader>
          <CardTitle className='text-base'>Resultado por área</CardTitle>
        </CardHeader>
        <CardContent>
          <SemaphoreTable areaResults={screening.area_results} />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className='text-base'>Hallazgos</CardTitle>
        </CardHeader>
        <CardContent>
          <FindingsList findings={screening.findings} />
        </CardContent>
      </Card>

      <ConfirmatoryTestSection screening={screening} onSaved={invalidate} />

      <Card>
        <CardHeader>
          <CardTitle className='text-base'>Historial de calificaciones</CardTitle>
        </CardHeader>
        <CardContent>
          <ScreeningTimeline scoreRuns={screening.score_runs} />
        </CardContent>
      </Card>

      {rescoreOpen && (
        <RescoreModal
          screening={screening}
          onClose={() => setRescoreOpen(false)}
          onDone={() => {
            setRescoreOpen(false)
            invalidate()
          }}
        />
      )}

      {invalidateOpen && (
        <InvalidateModal
          screeningId={screening.id}
          onClose={() => setInvalidateOpen(false)}
          onDone={() => {
            setInvalidateOpen(false)
            invalidate()
          }}
        />
      )}

      <ConfirmDialog
        isOpen={correctConfirmOpen}
        title='Corregir respuestas'
        description='Esta prueba ya está calificada. Si cambias alguna respuesta, vuelve a borrador y tendrás que calificarla de nuevo.'
        confirmLabel='Corregir respuestas'
        onConfirm={() => navigate(applyPath)}
        onCancel={() => setCorrectConfirmOpen(false)}
      />
    </div>
  )
}
