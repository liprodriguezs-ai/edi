import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { childFullName, type IChild } from '@/modules/child/schema'
import { ChildResource } from '@/modules/child/services/ChildResource'
import { BirthRecordForm } from '@/modules/child/components/BirthRecordForm'
import { ChildCaregiverPanel } from '@/modules/caregiver/components/ChildCaregiverPanel'
import { VisitFormModal } from '@/modules/visit/components/VisitFormModal'
import { VisitResource } from '@/modules/visit/services/VisitResource'
import type { IVisit } from '@/modules/visit/schema'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Pencil, Plus } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { Navigate, useNavigate, useParams } from 'react-router-dom'

export function ChildDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const [child, setChild] = useState<IChild | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [visits, setVisits] = useState<IVisit[]>([])
  const [isLoadingVisits, setIsLoadingVisits] = useState(true)
  const [isVisitFormOpen, setIsVisitFormOpen] = useState(false)

  const fetchVisits = useCallback(async () => {
    if (!id) return
    const response = await VisitResource.search({
      filters: [{ field: 'child_id', operator: '=', value: id }],
      sort: [{ field: 'visit_date', direction: 'desc' }],
      includes: [{ relation: 'health_center' }, { relation: 'practitioner' }],
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
    ChildResource.show(id)
      .then(setChild)
      .finally(() => setIsLoading(false))
    fetchVisits().finally(() => setIsLoadingVisits(false))
  }, [id, fetchVisits])

  if (!id) return <Navigate to="/child" replace />

  if (isLoading) {
    return <div className="p-6 text-sm text-muted-foreground">Cargando…</div>
  }

  if (!child) {
    return <div className="p-6 text-sm text-muted-foreground">No se encontró el expediente.</div>
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={childFullName(child)}
        description="Expediente clínico del niño"
        actions={
          <PermissionGuard permission="update.child">
            <Button
              variant="outline"
              onClick={() => navigate(`/child/${id}/edit`)}
              className="flex items-center gap-2"
            >
              <Pencil className="h-4 w-4" />
              Editar
            </Button>
          </PermissionGuard>
        }
      />

      <Tabs defaultValue="general">
        <TabsList>
          <TabsTrigger value="general">Datos generales</TabsTrigger>
          <TabsTrigger value="caregivers">Cuidadores</TabsTrigger>
          <TabsTrigger value="birth-record">Antecedentes perinatales</TabsTrigger>
          <TabsTrigger value="visits">Visitas</TabsTrigger>
        </TabsList>

        <TabsContent value="general">
          <Card>
            <CardContent className="grid grid-cols-1 gap-4 pt-6 sm:grid-cols-2">
              <Field label="Nombre completo" value={childFullName(child)} />
              <Field
                label="CURP"
                value={child.curp || '—'}
                warning={child.curp_mismatch ? 'No coincide con fecha de nacimiento / sexo capturados' : undefined}
              />
              <Field label="Fecha de nacimiento" value={displayFormatDate(child.birth_date, 'mediumDate')} />
              <Field label="Sexo" value={child.sex?.name ?? '—'} />
              <Field label="Centro de salud" value={child.health_center?.name ?? '—'} />
              <Field label="Expediente (sistema del centro)" value={child.external_id || '—'} />
              <Field label="Asiste a escuela/guardería" value={child.is_schooled ? 'Sí' : 'No'} />
              <Field label="Notas" value={child.notes || '—'} />
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="caregivers">
          <Card>
            <CardContent className="pt-6">
              <ChildCaregiverPanel childId={id} />
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="birth-record">
          <Card>
            <CardContent className="pt-6">
              <BirthRecordForm childId={id} />
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="visits">
          <Card>
            <CardContent className="space-y-4 pt-6">
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold">Visitas</h3>
                <PermissionGuard permission="create.visit">
                  <Button size="sm" onClick={() => setIsVisitFormOpen(true)} className="flex items-center gap-1.5">
                    <Plus className="h-4 w-4" />
                    Nueva visita
                  </Button>
                </PermissionGuard>
              </div>

              {isLoadingVisits ? (
                <p className="text-sm text-muted-foreground">Cargando…</p>
              ) : visits.length === 0 ? (
                <p className="text-sm text-muted-foreground">Este niño aún no tiene visitas registradas.</p>
              ) : (
                <ul className="space-y-2">
                  {visits.map((visit) => (
                    <li
                      key={visit.id}
                      className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border p-3"
                    >
                      <span className="font-medium">{visit.receipt_number}</span>
                      <span className="text-sm text-muted-foreground">
                        {displayFormatDate(visit.visit_date, 'mediumDate')}
                      </span>
                      <span className="text-sm text-muted-foreground">
                        {visit.health_center?.name ?? '—'}
                      </span>
                      <span className="text-sm text-muted-foreground">
                        {visit.practitioner?.user?.name ?? '—'}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      <VisitFormModal
        isOpen={isVisitFormOpen}
        childId={id}
        onClose={() => setIsVisitFormOpen(false)}
        onCreated={() => void refreshVisits()}
      />
    </div>
  )
}

function Field({ label, value, warning }: { label: string; value: string; warning?: string }) {
  return (
    <div className="space-y-1">
      <p className="text-xs font-medium text-muted-foreground">{label}</p>
      <p className="text-sm">{value}</p>
      {warning && <p className="text-xs text-amber-600">⚠︎ {warning}</p>}
    </div>
  )
}
