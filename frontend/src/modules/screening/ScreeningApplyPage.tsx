import { Button } from '@/components/ui/button'
import { childFullName } from '@/modules/child/schema'
import { AppliedGroupSummary } from '@/modules/screening/components/AppliedGroupSummary'
import { AreaNav } from '@/modules/screening/components/AreaNav'
import { AreaSection } from '@/modules/screening/components/AreaSection'
import { CloseScreeningAction } from '@/modules/screening/components/CloseScreeningAction'
import { EdiToolsBar } from '@/modules/screening/components/EdiToolsBar'
import { FindingsList } from '@/modules/screening/components/FindingsList'
import { GlobalResultCard } from '@/modules/screening/components/GlobalResultCard'
import { RiskFactorSection } from '@/modules/screening/components/RiskFactorSection'
import { SaveStatusIndicator } from '@/modules/screening/components/SaveStatusIndicator'
import { ScreeningReview } from '@/modules/screening/components/ScreeningReview'
import { ScreeningSteps } from '@/modules/screening/components/ScreeningSteps'
import { NextAppointmentButton } from '@/modules/appointment/components/NextAppointmentButton'
import { SemaphoreTable } from '@/modules/screening/components/SemaphoreTable'
import { SomatometryFields } from '@/modules/screening/components/SomatometryFields'
import { ScreeningNutritionalStatus } from '@/modules/nutrition/components/ScreeningNutritionalStatus'
import { useScreeningApplication } from '@/modules/screening/hooks/useScreeningApplication'
import { useScreeningDraftStore } from '@/modules/screening/store/screeningDraftStore'
import { CHOSEN_GROUP_PARAM } from '@/modules/screening/utils/ageGroupCopy'
import { buildCaptureOverview } from '@/modules/screening/utils/captureOverview'
import { screeningStepIndex, type CaptureView } from '@/modules/screening/utils/statusCopy'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { useWarnBeforeUnload } from '@/shared/hooks/useWarnBeforeUnload'
import { formatAgeDays } from '@/shared/utils/ageFormat'
import { Skeleton } from 'boneyard-js/react'
import { useEffect, useRef, useState } from 'react'
import { Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Callout } from '@/shared/components/atoms/Callout'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { SectionLabel } from '@/shared/components/atoms/SectionLabel'
import { Panel } from '@/shared/components/molecules/Panel'
import { Lock, TriangleAlert } from 'lucide-react'

/**
 * REQ-SUI-003 — the single custom surface (NOT `useBaseCrud`). Create route
 * (`/screening/apply/:visitId`) or resume route (`/screening/:id/apply`).
 *
 * Capture follows the official form order (risk factors and alert signs,
 * development axes, neurological exam and alarm signs), with sticky section
 * chips, and ends in a review step: "Calificar" is offered only once nothing
 * required is missing.
 */
export function ScreeningApplyPage() {
  const { visitId, id } = useParams<{ visitId?: string; id?: string }>()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const chosenAgeGroupId = searchParams.get(CHOSEN_GROUP_PARAM) ?? undefined

  const { phase, notApplicableMessage, selectAnswer, selectRiskFactor, saveSomatometry, score } =
    useScreeningApplication({ visitId, screeningId: id, ageGroupId: chosenAgeGroupId })

  const draft = useScreeningDraftStore()
  const [view, setView] = useState<CaptureView>('capture')
  const reviewRef = useRef<HTMLDivElement>(null)
  const pendingScrollRef = useRef<string | null>(null)

  // Reset the wizard store when the page unmounts (D1/D3) so a later
  // navigation to another screening starts clean.
  useEffect(() => () => useScreeningDraftStore.getState().reset(), [])

  // Closing or reloading the tab with edits not saved yet asks first.
  const hasUnsavedChanges =
    Object.keys(draft.pending.answers).length > 0 ||
    Object.keys(draft.pending.riskFactors).length > 0 ||
    draft.syncStatus === 'saving' ||
    draft.syncStatus === 'offline'
  useWarnBeforeUnload(hasUnsavedChanges)

  // Bring into view the previous-group block that just appeared (while
  // capturing, or after a score attempt), or the first item a score attempt
  // marked as missing. Focus is not moved, so capture is never interrupted.
  const alternateKeys = Object.keys(draft.alternateRequired).join('|')
  const incompleteCount = draft.incompleteItemIds.length
  const previousRef = useRef({ alternateKeys, incompleteCount })
  useEffect(() => {
    const previous = previousRef.current
    previousRef.current = { alternateKeys, incompleteCount }
    const known = new Set(previous.alternateKeys.split('|'))
    const added = alternateKeys.split('|').find((key) => key !== '' && !known.has(key))
    const selector = added
      ? `[data-alternate-block="${added}"]`
      : incompleteCount > previous.incompleteCount
        ? '[data-incomplete]'
        : null
    if (!selector) return
    document.querySelector<HTMLElement>(selector)?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' })
  }, [alternateKeys, incompleteCount])

  // Switching views: the review starts at its top; going back to capture
  // lands on the section the evaluator asked for.
  useEffect(() => {
    if (view === 'review') {
      reviewRef.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
      return
    }
    const target = pendingScrollRef.current
    pendingScrollRef.current = null
    if (target) document.getElementById(target)?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
  }, [view])

  const goToSection = (anchorId: string) => {
    if (view === 'review') {
      pendingScrollRef.current = anchorId
      setView('capture')
      return
    }
    document.getElementById(anchorId)?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
  }

  const scoreFromReview = async () => {
    await score()
    // A 422 (missing items / previous group) sends the evaluator back to capture.
    if (useScreeningDraftStore.getState().scorePhase !== 'result') setView('capture')
  }

  if (!visitId && !id) return <Navigate to='/screening' replace />

  if (phase === 'not_applicable') {
    return (
      <EntityNotFound
        variant='error'
        title='Prueba EDI no aplicable'
        description={notApplicableMessage ?? undefined}
        backTo='/screening'
        backLabel='Volver a Prueba EDI'
      />
    )
  }

  if (phase === 'loading' || phase === 'redirecting' || !draft.screening || !draft.bank) {
    return (
      <Skeleton name='screening-apply' loading>
        <div className='space-y-6'>
          <div className='h-8 w-64 rounded bg-muted' />
          <div className='h-64 rounded bg-muted' />
        </div>
      </Skeleton>
    )
  }

  const { screening, bank } = draft
  const isFrozen = draft.syncStatus === 'frozen'
  const isResultPhase = draft.scorePhase === 'result'
  const isScoring = draft.scorePhase === 'scoring'
  const statusKey = screening.screening_status?.key
  const step = screeningStepIndex(statusKey, view)
  const detailPath = `/screening/${screening.id}`

  const overview = buildCaptureOverview(bank, draft.answers, draft.riskFactorAnswers, draft.alternateRequired)
  const progressPct = overview.total > 0 ? Math.round((overview.answered / overview.total) * 100) : 0

  return (
    <div className='space-y-6 pb-24'>
      <div className='space-y-2'>
        <PageHeader
          back={{ to: `/child/${screening.child_id}?tab=pruebas`, label: 'Volver al expediente' }}
          title={screening.child ? childFullName(screening.child) : 'Prueba EDI'}
          meta={
            <span className='flex flex-wrap items-center gap-x-1.5 gap-y-0.5'>
              <span>Edad</span>
              <span className='font-medium text-foreground'>
                {formatAgeDays(screening.chronological_age_days)}
              </span>
              {screening.corrected_age_days != null && (
                <>
                  <span>· corregida</span>
                  <span className='font-medium text-foreground'>
                    {formatAgeDays(screening.corrected_age_days)}
                  </span>
                </>
              )}
            </span>
          }
          actions={
            <div className='flex w-full flex-col items-start gap-2 sm:w-auto sm:items-end'>
              <SaveStatusIndicator status={draft.syncStatus} />
              <EdiToolsBar />
            </div>
          }
        />
        {step !== null && <ScreeningSteps current={step} />}
        <AppliedGroupSummary
          screening={screening}
          fallbackCode={bank.applied_age_group_code}
          showMaterials
        />
      </div>

      {statusKey === 'scored' && !isResultPhase && (
        <Callout icon={TriangleAlert} tone='warning' className='text-sm'>
          Esta prueba ya está calificada. Si cambias alguna respuesta, vuelve a borrador y tendrás que
          calificarla de nuevo.
        </Callout>
      )}

      {isFrozen && (
        <Callout icon={Lock} tone='info' className='text-sm'>
          Esta Prueba EDI está congelada y ya no admite cambios.{' '}
          <Button
            variant='link'
            className='h-auto px-0 py-0'
            onClick={() => navigate(`/screening/${screening.id}`)}
          >
            Ver detalle
          </Button>
        </Callout>
      )}

      {isResultPhase ? (
        <div className='space-y-6'>
          <GlobalResultCard screening={screening} />
          <Panel>
            <SemaphoreTable areaResults={screening.area_results} />
          </Panel>
          <Panel>
            <FindingsList findings={screening.findings} />
          </Panel>
          <div className='sticky bottom-0 -mx-4 flex flex-wrap items-center justify-between gap-3 border-t border-border bg-background/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6'>
            <p className='text-sm text-muted-foreground'>
              {statusKey === 'completed'
                ? 'Prueba cerrada.'
                : 'Revisa el resultado. Al cerrar la prueba ya no podrás cambiar respuestas.'}
            </p>
            <div className='flex flex-wrap gap-2'>
              <Button variant='ghost' onClick={() => navigate(detailPath)}>
                Ver detalle
              </Button>
              {statusKey === 'scored' && (
                <>
                  <Button variant='outline' onClick={() => draft.setScorePhase('editing')}>
                    Corregir respuestas
                  </Button>
                  <CloseScreeningAction
                    screeningId={screening.id}
                    child={screening.child}
                    onDone={() => navigate(detailPath)}
                  />
                </>
              )}
              {statusKey === 'completed' && screening.child && <NextAppointmentButton child={screening.child} />}
            </div>
          </div>
        </div>
      ) : view === 'review' ? (
        <div ref={reviewRef} className='scroll-mt-4'>
          <ScreeningReview
            entries={overview.entries}
            missingCount={overview.missingRequired}
            isScoring={isScoring}
            disabled={isFrozen}
            onBack={() => setView('capture')}
            onGoTo={goToSection}
            onScore={() => void scoreFromReview()}
          />
        </div>
      ) : (
        <>
          <AreaNav entries={overview.entries} onGoTo={goToSection} />

          <section>
            <SectionLabel as='h2' className='mb-2.5'>
              Somatometría
            </SectionLabel>
            <Panel as='div'>
              <SomatometryFields
                key={screening.id}
                screening={screening}
                onSave={saveSomatometry}
                disabled={isFrozen}
              />
              <PermissionGuard permission='read.nutritional_assessment'>
                <div className='mt-5 border-t border-border pt-4'>
                  <p className='mb-2 text-xs font-medium text-muted-foreground'>
                    Estado nutricional
                  </p>
                  <ScreeningNutritionalStatus
                    screeningId={screening.id}
                    measuresKey={`${screening.weight_grams ?? ''}-${screening.height_mm ?? ''}`}
                  />
                </div>
              </PermissionGuard>
            </Panel>
          </section>

          <RiskFactorSection
            riskFactors={bank.risk_factors}
            answers={draft.riskFactorAnswers}
            onChange={selectRiskFactor}
          />

          {overview.areas.map((area) => (
            <AreaSection
              key={area.development_area_id ?? area.code}
              area={area}
              answers={draft.answers}
              onAnswer={selectAnswer}
              incompleteItemIds={draft.incompleteItemIds}
              alternateItems={draft.alternateRequired[area.development_area_id ?? 'unknown']}
            />
          ))}

          <div className='sticky bottom-0 -mx-4 flex flex-wrap items-center justify-between gap-4 border-t border-border bg-background/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6'>
            <div className='flex min-w-[220px] flex-1 flex-wrap items-center gap-x-3 gap-y-1'>
              <div className='h-[6px] min-w-[120px] flex-1 overflow-hidden bg-border'>
                <div
                  className='h-full bg-primary transition-all duration-300'
                  style={{ width: `${progressPct}%` }}
                  aria-hidden
                />
              </div>
              <span className='whitespace-nowrap font-mono text-[11px] tabular-nums text-muted-foreground'>
                {overview.answered}/{overview.total} contestados
              </span>
              {overview.pendingAlternates > 0 && (
                <span className='whitespace-nowrap font-mono text-[11px] font-semibold text-[var(--y)]'>
                  Faltan {overview.pendingAlternates} del grupo anterior
                </span>
              )}
            </div>
            <Button size='lg' onClick={() => setView('review')} disabled={isFrozen || isScoring}>
              Revisar y calificar
            </Button>
          </div>
        </>
      )}
    </div>
  )
}
