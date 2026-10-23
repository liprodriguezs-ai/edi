import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { ApplyModeFields, type ApplyMode } from '@/modules/screening/components/ApplyModeFields'
import type { IScreening } from '@/modules/screening/schema'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { CHOSEN_GROUP_PARAM } from '@/modules/screening/utils/ageGroupCopy'
import { canContinueApplication } from '@/modules/screening/utils/statusCopy'
import type { IVisit } from '@/modules/visit/schema'
import { VisitResource } from '@/modules/visit/services/VisitResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { sessionStore } from '@/shared/auth/sessionStore'
import { Callout } from '@/shared/components/atoms/Callout'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { Modal } from '@/shared/components/Modal'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useCatalogs, type ICatalogItem } from '@/shared/hooks/useCatalogs'
import { useTenantTimeZone } from '@/shared/hooks/useTenantTimeZone'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { tenantToday } from '@/shared/utils/tenantTime'
import { TriangleAlert } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useStore } from 'zustand'

interface NewScreeningChild {
  id: string
  health_center_id?: string | null
}

interface NewScreeningDialogProps {
  isOpen: boolean
  child: NewScreeningChild
  /** An unfinished test of the child (any day): offered first, never forced. */
  openScreening?: IScreening | null
  onClose: () => void
}

type PractitionerItem = ICatalogItem & { user_id?: string | null; user?: { id?: string; name?: string } | null }

/** What today already holds for this child. */
type TodayState =
  | { kind: 'loading' }
  | { kind: 'none' }
  | { kind: 'free-visit'; visit: IVisit }
  | { kind: 'in-progress'; screening: IScreening }

/**
 * "Nueva Prueba EDI" from the child record. A test always hangs from a visit,
 * so the dialog reuses today's visit when it has no test yet, continues the
 * test already started today, or creates today's visit (child's center and the
 * user's own practitioner profile as defaults) before opening the capture.
 */
export function NewScreeningDialog(props: NewScreeningDialogProps) {
  if (!props.isOpen) return null
  return <NewScreeningDialogBody {...props} />
}

async function findToday(childId: string, today: string): Promise<TodayState> {
  // `visit_date` is not filterable on the API; the child's latest visits are
  // enough to find today's (a child rarely has more than one visit a day).
  const response = await VisitResource.search({
    filters: [{ field: 'child_id', operator: '=', value: childId }],
    sort: [{ field: 'visit_date', direction: 'desc' }],
    limit: 5,
  })
  let freeVisit: IVisit | null = null
  for (const visit of response.data.filter((item) => item.visit_date === today)) {
    const screening = await ScreeningResource.findByVisitId(visit.id)
    if (!screening) {
      freeVisit ??= visit
      continue
    }
    if (canContinueApplication(screening.screening_status?.key)) return { kind: 'in-progress', screening }
  }
  return freeVisit ? { kind: 'free-visit', visit: freeVisit } : { kind: 'none' }
}

function NewScreeningDialogBody({ child, openScreening, onClose }: NewScreeningDialogProps) {
  const navigate = useNavigate()
  const timeZone = useTenantTimeZone()
  const today = tenantToday(timeZone)
  const userId = useStore(sessionStore, (state) => state.profile?.user?.id)

  const [todayState, setTodayState] = useState<TodayState>({ kind: 'loading' })
  const [mode, setMode] = useState<ApplyMode>('age')
  const [ageGroupId, setAgeGroupId] = useState<string | null>(null)
  const [healthCenterId, setHealthCenterId] = useState<string | null>(child.health_center_id ?? null)
  const [chosenPractitionerId, setChosenPractitionerId] = useState<string | null>(null)
  const [isStarting, setIsStarting] = useState(false)

  const { catalogs, isLoading: isLoadingCatalogs } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.HEALTH_CENTER]: { _sort: 'name' },
        [CatalogType.PRACTITIONER_PROFILE]: { _with: 'user' },
      }),
      []
    )
  )
  const practitioners = (catalogs[CatalogType.PRACTITIONER_PROFILE] ?? []) as PractitionerItem[]
  const ownPractitionerId =
    practitioners.find((item) => (item.user_id ?? item.user?.id) === userId)?.id ?? null
  const practitionerId = chosenPractitionerId ?? ownPractitionerId

  useEffect(() => {
    let cancelled = false
    findToday(child.id, today)
      .then((state) => {
        if (!cancelled) setTodayState(state)
      })
      .catch(() => {
        if (!cancelled) setTodayState({ kind: 'none' })
      })
    return () => {
      cancelled = true
    }
  }, [child.id, today])

  const needsVisit = todayState.kind === 'none'
  const canStart =
    !isStarting &&
    (todayState.kind === 'free-visit' || (needsVisit && healthCenterId !== null && practitionerId !== null)) &&
    (mode === 'age' || ageGroupId !== null)

  const start = async () => {
    setIsStarting(true)
    try {
      const visit =
        todayState.kind === 'free-visit'
          ? todayState.visit
          : await VisitResource.create({
              child_id: child.id,
              health_center_id: healthCenterId ?? '',
              practitioner_id: practitionerId ?? '',
              physical_receipt_number: '',
              visit_date: today,
            })
      const query =
        mode === 'chosen' && ageGroupId ? `?${new URLSearchParams({ [CHOSEN_GROUP_PARAM]: ageGroupId }).toString()}` : ''
      onClose()
      navigate(`/screening/apply/${visit.id}${query}`)
    } catch (err) {
      if (err instanceof ApiError) {
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      } else {
        toast.error('No se pudo empezar la Prueba EDI')
      }
    } finally {
      setIsStarting(false)
    }
  }

  const inProgress = todayState.kind === 'in-progress' ? todayState.screening : null
  // Today's open test already takes over the dialog; an older one is only a warning.
  const olderOpen = !inProgress && openScreening ? openScreening : null
  const continueTest = (id: string) => {
    onClose()
    navigate(`/screening/${id}/apply`)
  }

  return (
    <Modal
      isOpen
      onClose={onClose}
      title='Nueva Prueba EDI'
      footer={
        <>
          <Button type='button' variant='outline' onClick={onClose} disabled={isStarting}>
            Cancelar
          </Button>
          {inProgress ? (
            <Button
              type='button'
              onClick={() => continueTest(inProgress.id)}
            >
              Continuar captura
            </Button>
          ) : (
            <Button type='button' onClick={() => void start()} disabled={!canStart} loading={isStarting}>
              Empezar Prueba EDI
            </Button>
          )}
        </>
      }
    >
      {todayState.kind === 'loading' ? (
        <InlineLoading name='new-screening-today' className='py-3' />
      ) : inProgress ? (
        <p className='text-sm text-muted-foreground'>
          Este niño ya tiene una Prueba EDI empezada hoy. Continúa esa captura en lugar de abrir otra.
        </p>
      ) : (
        <FieldGroup>
          {olderOpen && (
            <Callout icon={TriangleAlert} tone='warning'>
              <div className='flex flex-wrap items-center justify-between gap-3'>
                <p className='text-sm'>
                  Este niño tiene una Prueba EDI sin terminar del{' '}
                  {displayFormatDate(olderOpen.visit?.visit_date, 'mediumDate')}. Puedes continuarla o empezar una nueva.
                </p>
                <Button type='button' size='sm' variant='outline' onClick={() => continueTest(olderOpen.id)}>
                  Continuar esa prueba
                </Button>
              </div>
            </Callout>
          )}
          {todayState.kind === 'free-visit' ? (
            <p className='text-sm text-muted-foreground'>
              Se usará la visita de hoy <span className='font-mono text-foreground'>{todayState.visit.receipt_number}</span>.
            </p>
          ) : (
            <>
              <p className='text-sm text-muted-foreground'>Se registrará la visita de hoy para este niño.</p>
              <Field>
                <FieldLabel htmlFor='new-screening-center' required>
                  Centro de salud
                </FieldLabel>
                <SearchSelect<ICatalogItem>
                  id='new-screening-center'
                  value={healthCenterId}
                  onChange={(value) => setHealthCenterId((value as string | null) ?? null)}
                  options={catalogs[CatalogType.HEALTH_CENTER] ?? []}
                  isLoading={isLoadingCatalogs}
                  isDisabled={isStarting}
                  placeholder='Seleccionar…'
                />
              </Field>
              <Field>
                <FieldLabel htmlFor='new-screening-practitioner' required>
                  Profesional que aplica
                </FieldLabel>
                <SearchSelect<PractitionerItem>
                  id='new-screening-practitioner'
                  value={practitionerId}
                  onChange={(value) => setChosenPractitionerId((value as string | null) ?? null)}
                  options={practitioners}
                  getOptionLabel={(item) => item.user?.name || item.name || 'Profesional'}
                  isLoading={isLoadingCatalogs}
                  isDisabled={isStarting}
                  placeholder='Seleccionar…'
                />
                <FieldDescription>Por defecto, tu perfil de profesional.</FieldDescription>
              </Field>
            </>
          )}
          <ApplyModeFields
            mode={mode}
            onModeChange={setMode}
            ageGroupId={ageGroupId}
            onAgeGroupChange={setAgeGroupId}
            disabled={isStarting}
          />
        </FieldGroup>
      )}
    </Modal>
  )
}
