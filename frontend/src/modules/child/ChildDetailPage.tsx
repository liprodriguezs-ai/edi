import { PageLoader } from '@/shared/components/PageLoader'
import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { DescriptionItem, DescriptionList } from '@/shared/components/molecules/DescriptionList'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { Panel } from '@/shared/components/molecules/Panel'
import { childFullName, type IChild } from '@/modules/child/schema'
import { ChildResource } from '@/modules/child/services/ChildResource'
import { BirthRecordForm } from '@/modules/child/components/BirthRecordForm'
import { ChildSummaryCards } from '@/modules/child/components/ChildSummaryCards'
import {
  lastScoredScreening,
  latestOpenScreening,
  useChildFollowUps,
  useChildNextAppointment,
  useChildScreenings,
} from '@/modules/child/hooks/useChildRecord'
import { ChildCaregiverPanel } from '@/modules/caregiver/components/ChildCaregiverPanel'
import { AppointmentFormSheet } from '@/modules/appointment/components/AppointmentFormSheet'
import { ChildAppointmentsPanel } from '@/modules/appointment/components/ChildAppointmentsPanel'
import { ChildNutritionTab } from '@/modules/nutrition/components/ChildNutritionTab'
import { ChildScreeningsPanel } from '@/modules/screening/components/ChildScreeningsPanel'
import { ChildFollowUpsPanel } from '@/modules/followUp/components/ChildFollowUpsPanel'
import { isOpenFollowUp } from '@/modules/followUp/utils/statusCopy'
import { NewScreeningDialog } from '@/modules/screening/components/NewScreeningDialog'
import { VisitFormModal } from '@/modules/visit/components/VisitFormModal'
import { VisitResource } from '@/modules/visit/services/VisitResource'
import type { IVisit } from '@/modules/visit/schema'
import { useMultiplePermissions } from '@/shared/hooks/usePermissions'
import { useTenantTimeZone } from '@/shared/hooks/useTenantTimeZone'
import { formatAgeDays } from '@/shared/utils/ageFormat'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { civilDaysBetween, tenantToday } from '@/shared/utils/tenantTime'
import { CalendarPlus, ClipboardCheck, Pencil, Plus } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { Navigate, useNavigate, useParams, useSearchParams } from 'react-router-dom'

/** Tabs of the record; `?tab=` selects one so other screens can link back to it. */
const TABS = ['resumen', 'pruebas', 'citas', 'seguimientos', 'visitas', 'nutricion', 'datos'] as const
type RecordTab = (typeof TABS)[number]

function isRecordTab(value: string | null): value is RecordTab {
  return TABS.includes(value as RecordTab)
}

/**
 * Expediente clínico del niño (odd/tasks/edi-clinical-record.md S4): the center
 * of the clinical work. Resumen first, then the child's Pruebas EDI (where every
 * test is started, continued and opened), appointments, visits, nutrition and
 * personal data.
 */
export function ChildDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const timeZone = useTenantTimeZone()

  const [child, setChild] = useState<IChild | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [visits, setVisits] = useState<IVisit[]>([])
  const [isLoadingVisits, setIsLoadingVisits] = useState(true)
  const [isVisitFormOpen, setIsVisitFormOpen] = useState(false)
  const [isNewScreeningOpen, setIsNewScreeningOpen] = useState(false)
  const [isBookingOpen, setIsBookingOpen] = useState(false)

  const permissions = useMultiplePermissions({
    readAppointments: 'read.appointment',
    readFollowUps: 'read.follow_up',
    readScreenings: 'read.screening',
  })

  const childId = id ?? ''
  const screenings = useChildScreenings(childId, Boolean(id) && permissions.readScreenings)
  const nextAppointment = useChildNextAppointment(childId, Boolean(id) && permissions.readAppointments)
  const followUps = useChildFollowUps(childId, Boolean(id) && permissions.readFollowUps)

  const requestedTab = searchParams.get('tab')
  const tab: RecordTab = isRecordTab(requestedTab) ? requestedTab : 'resumen'
  const selectTab = (value: string) => setSearchParams(value === 'resumen' ? {} : { tab: value }, { replace: true })

  const fetchVisits = useCallback(async () => {
    if (!id) return
    const response = await VisitResource.search({
      filters: [{ field: 'child_id', operator: '=', value: id }],
      sort: [{ field: 'visit_date', direction: 'desc' }],
      includes: [
        { relation: 'health_center' },
        { relation: 'practitioner' },
        { relation: 'practitioner.user' },
      ],
    })
    setVisits(response.data)
  }, [id])

  /** Refresh tras registrar una visita (event handler, no efecto): sí togglea loading. */
  const refreshVisits = useCallback(async () => {
    setIsLoadingVisits(true)
    try {
      await fetchVisits()
    } finally {
      setIsLoadingVisits(false)
    }
  }, [fetchVisits])

  useEffect(() => {
    if (!id) return
    // Initial mount: isLoading/isLoadingVisits already start `true`, no sync
    // setState needed here (react-hooks/set-state-in-effect).
    ChildResource.show(id, { include: 'sex,health_center' })
      .then(setChild)
      .finally(() => setIsLoading(false))
    fetchVisits().finally(() => setIsLoadingVisits(false))
  }, [id, fetchVisits])

  if (!id) return <Navigate to='/child' replace />

  if (isLoading) {
    return <PageLoader />
  }

  if (!child) {
    return (
      <EntityNotFound title='No se encontró el expediente' backTo='/child' backLabel='Volver a niños' />
    )
  }

  const lastScreening = lastScoredScreening(screenings.data ?? [])
  const openScreening = latestOpenScreening(screenings.data ?? [])
  const ageDays = civilDaysBetween(child.birth_date, tenantToday(timeZone))

  return (
    <div className='space-y-6'>
      <PageHeader
        back={{ to: '/child', label: 'Volver a niños' }}
        title={childFullName(child)}
        description={
          <>
            {formatAgeDays(ageDays)}
            {child.health_center?.name && ` · ${child.health_center.name}`}
          </>
        }
        meta={
          lastScreening?.result_color ? (
            <span className='flex items-center gap-2 text-xs'>
              Última Prueba EDI
              <ResultColorPill colorKey={lastScreening.result_color.key} />
            </span>
          ) : undefined
        }
        actions={
          <div className='flex flex-wrap gap-2'>
            <PermissionGuard permission='update.child'>
              <Button variant='outline' onClick={() => navigate(`/child/${id}/edit`)}>
                <Pencil className='size-4' aria-hidden />
                Editar
              </Button>
            </PermissionGuard>
            <PermissionGuard permission='create.appointment'>
              <Button variant='outline' onClick={() => setIsBookingOpen(true)}>
                <CalendarPlus className='size-4' aria-hidden />
                Agendar cita
              </Button>
            </PermissionGuard>
            <PermissionGuard permission='create.screening'>
              <Button onClick={() => setIsNewScreeningOpen(true)}>
                <ClipboardCheck className='size-4' aria-hidden />
                Nueva Prueba EDI
              </Button>
            </PermissionGuard>
          </div>
        }
      />

      <Tabs value={tab} onValueChange={selectTab}>
        <TabsList>
          <TabsTrigger value='resumen'>Resumen</TabsTrigger>
          <PermissionGuard permission='read.screening'>
            <TabsTrigger value='pruebas'>Pruebas EDI</TabsTrigger>
          </PermissionGuard>
          <PermissionGuard permission='read.appointment'>
            <TabsTrigger value='citas'>Citas</TabsTrigger>
          </PermissionGuard>
          <PermissionGuard permission='read.follow_up'>
            <TabsTrigger value='seguimientos'>Seguimientos</TabsTrigger>
          </PermissionGuard>
          <TabsTrigger value='visitas'>Visitas</TabsTrigger>
          <PermissionGuard permission='read.nutritional_assessment'>
            <TabsTrigger value='nutricion'>Estado nutricional</TabsTrigger>
          </PermissionGuard>
          <TabsTrigger value='datos'>Datos y cuidadores</TabsTrigger>
        </TabsList>

        <TabsContent value='resumen'>
          <ChildSummaryCards
            childId={id}
            timeZone={timeZone}
            nextAppointment={nextAppointment.data ?? null}
            lastScreening={lastScreening}
            openFollowUps={(followUps.data ?? []).filter(isOpenFollowUp)}
            isLoading={screenings.isLoading}
          />
        </TabsContent>

        <TabsContent value='pruebas'>
          <ChildScreeningsPanel
            screenings={screenings.data ?? []}
            isLoading={screenings.isLoading}
            onNew={() => setIsNewScreeningOpen(true)}
          />
        </TabsContent>

        <TabsContent value='citas'>
          <ChildAppointmentsPanel child={child} />
        </TabsContent>

        <TabsContent value='seguimientos'>
          <ChildFollowUpsPanel followUps={followUps.data ?? []} isLoading={followUps.isLoading} />
        </TabsContent>

        <TabsContent value='nutricion'>
          <ChildNutritionTab childId={id} />
        </TabsContent>

        <TabsContent value='datos' className='space-y-6'>
          <DescriptionList columns={2}>
            <DescriptionItem label='Nombre completo' value={childFullName(child)} />
            <DescriptionItem
              label='CURP'
              value={child.curp || '—'}
              mono
              hint={
                child.curp_mismatch
                  ? 'No coincide con fecha de nacimiento / sexo capturados'
                  : undefined
              }
            />
            <DescriptionItem
              label='Fecha de nacimiento'
              value={displayFormatDate(child.birth_date, 'mediumDate')}
              mono
            />
            <DescriptionItem label='Sexo' value={child.sex?.name ?? '—'} />
            <DescriptionItem label='Centro de salud' value={child.health_center?.name ?? '—'} />
            <DescriptionItem label='Expediente (sistema del centro)' value={child.external_id || '—'} mono />
            <DescriptionItem label='Asiste a escuela/guardería' value={child.is_schooled ? 'Sí' : 'No'} />
            <DescriptionItem label='Notas' value={child.notes || '—'} />
          </DescriptionList>
          <Panel title='Cuidadores'>
            <ChildCaregiverPanel childId={id} />
          </Panel>
          <Panel title='Antecedentes perinatales'>
            <BirthRecordForm childId={id} />
          </Panel>
        </TabsContent>

        <TabsContent value='visitas'>
          <Panel
            padding='none'
            title='Visitas'
            actions={
              <PermissionGuard permission='create.visit'>
                <Button
                  size='sm'
                  onClick={() => setIsVisitFormOpen(true)}
                  className='flex items-center gap-1.5'
                >
                  <Plus className='h-4 w-4' />
                  Nueva visita
                </Button>
              </PermissionGuard>
            }
          >
            {isLoadingVisits ? (
              <InlineLoading name='child-visits' className='px-4 py-3' />
            ) : visits.length === 0 ? (
              <InlineEmpty className='px-4 py-3'>Este niño aún no tiene visitas registradas.</InlineEmpty>
            ) : (
              <ul className='divide-y divide-border'>
                {visits.map((visit) => (
                  <li
                    key={visit.id}
                    className='flex flex-wrap items-center justify-between gap-2 px-4 py-2.5'
                  >
                    <span className='font-mono font-medium'>{visit.receipt_number}</span>
                    <span className='font-mono text-sm tabular-nums text-muted-foreground'>
                      {displayFormatDate(visit.visit_date, 'mediumDate')}
                    </span>
                    <span className='text-sm text-muted-foreground'>
                      {visit.health_center?.name ?? '—'}
                    </span>
                    <span className='text-sm text-muted-foreground'>
                      {visit.practitioner?.user?.name ?? '—'}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </TabsContent>
      </Tabs>

      <VisitFormModal
        isOpen={isVisitFormOpen}
        childId={id}
        onClose={() => setIsVisitFormOpen(false)}
        onCreated={() => void refreshVisits()}
      />
      <NewScreeningDialog
        isOpen={isNewScreeningOpen}
        child={child}
        openScreening={openScreening}
        onClose={() => setIsNewScreeningOpen(false)}
      />
      <AppointmentFormSheet
        isOpen={isBookingOpen}
        mode='book'
        prefill={{ child }}
        onClose={() => setIsBookingOpen(false)}
      />
    </div>
  )
}
