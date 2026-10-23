import { PageLoader } from '@/shared/components/PageLoader'
import { Button } from '@/components/ui/button'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { Modal } from '@/shared/components/Modal'
import { DescriptionItem, DescriptionList } from '@/shared/components/molecules/DescriptionList'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { childFullName, type IChild } from '@/modules/child/schema'
import { ChildResource } from '@/modules/child/services/ChildResource'
import { type IStimulationGroup, type IStimulationGroupMember } from '@/modules/stimulationGroup/schema'
import { StimulationGroupFormModal } from '@/modules/stimulationGroup/StimulationGroupFormModal'
import { StimulationGroupResource } from '@/modules/stimulationGroup/services/StimulationGroupResource'
import { StimulationGroupMemberResource } from '@/modules/stimulationGroup/services/StimulationGroupMemberResource'
import { CrudMode } from '@/shared/enums/CrudMode'
import { CatalogType, useLazyCatalogs, type ICatalogItem } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { CalendarCheck, Pencil, Plus, Unlink } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { Navigate, useNavigate, useParams } from 'react-router-dom'

export function StimulationGroupDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()

  const [group, setGroup] = useState<IStimulationGroup | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [editOpen, setEditOpen] = useState(false)

  const [members, setMembers] = useState<IStimulationGroupMember[]>([])
  const [isLoadingMembers, setIsLoadingMembers] = useState(true)
  const [enrollOpen, setEnrollOpen] = useState(false)
  const [selectedChildId, setSelectedChildId] = useState<string | null>(null)
  const [selectedStatusId, setSelectedStatusId] = useState<string | null>(null)
  const [isEnrolling, setIsEnrolling] = useState(false)
  const [memberToRemove, setMemberToRemove] = useState<IStimulationGroupMember | null>(null)

  const { catalogs: statusCatalogs, isLoading: isLoadingStatuses, enable: enableStatuses } = useLazyCatalogs({
    [CatalogType.ATTENDANCE_PAYMENT_STATUS]: { _sort: 'name' },
  })

  const fetchGroup = useCallback(async () => {
    if (!id) return
    const data = await StimulationGroupResource.show(id, { include: 'stimulation_group_type,health_center,promoter' })
    setGroup(data)
  }, [id])

  const fetchMembers = useCallback(async () => {
    if (!id) return
    const response = await StimulationGroupMemberResource.search({
      filters: [{ field: 'stimulation_group_id', operator: '=', value: id }],
      includes: [{ relation: 'child' }, { relation: 'default_attendance_payment_status' }],
    })
    setMembers(response.data)
  }, [id])

  useEffect(() => {
    if (!id) return
    setIsLoading(true)
    fetchGroup().finally(() => setIsLoading(false))
    setIsLoadingMembers(true)
    fetchMembers().finally(() => setIsLoadingMembers(false))
  }, [id, fetchGroup, fetchMembers])

  const openEnrollDialog = useCallback(() => {
    setSelectedChildId(null)
    setSelectedStatusId(null)
    setEnrollOpen(true)
    enableStatuses()
  }, [enableStatuses])

  const handleEnroll = useCallback(async () => {
    if (!id || !selectedChildId || !selectedStatusId) {
      toast.error('Selecciona un niño y un estatus de pago')
      return
    }
    setIsEnrolling(true)
    try {
      await StimulationGroupMemberResource.create({
        stimulation_group_id: id,
        child_id: selectedChildId,
        default_attendance_payment_status_id: selectedStatusId,
      })
      toast.success('Niño inscrito en el grupo')
      setEnrollOpen(false)
      await fetchMembers()
    } catch (err) {
      if (err instanceof ApiError) {
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      } else {
        toast.error('No se pudo inscribir al niño')
      }
    } finally {
      setIsEnrolling(false)
    }
  }, [id, selectedChildId, selectedStatusId, fetchMembers])

  const handleRemoveConfirm = useCallback(async () => {
    if (!memberToRemove) return
    try {
      await StimulationGroupMemberResource.remove(memberToRemove.id)
      toast.success('Niño dado de baja del grupo')
      setMemberToRemove(null)
      await fetchMembers()
    } catch (err) {
      if (err instanceof ApiError) {
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      } else {
        toast.error('No se pudo dar de baja al niño')
      }
    }
  }, [memberToRemove, fetchMembers])

  if (!id) return <Navigate to="/stimulation-group" replace />

  if (isLoading) {
    return <PageLoader />
  }

  if (!group) {
    return (
      <EntityNotFound
        title="No se encontró el grupo"
        backTo="/stimulation-group"
        backLabel="Volver a grupos"
      />
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        back={{ to: '/stimulation-group', label: 'Volver a grupos' }}
        title={group.name}
        description="Grupo de estimulación temprana"
        actions={
          <div className="flex gap-2">
            <PermissionGuard permission="update.stimulation_group">
              <Button variant="outline" onClick={() => navigate(`/stimulation-group/${id}/attendance`)}>
                <CalendarCheck className="h-4 w-4" />
                Tomar asistencia
              </Button>
            </PermissionGuard>
            <PermissionGuard permission="update.stimulation_group">
              <Button variant="outline" onClick={() => setEditOpen(true)}>
                <Pencil className="h-4 w-4" />
                Editar
              </Button>
            </PermissionGuard>
          </div>
        }
      />

      <Tabs defaultValue="general">
        <TabsList>
          <TabsTrigger value="general">Datos generales</TabsTrigger>
          <TabsTrigger value="members">Miembros</TabsTrigger>
        </TabsList>

        <TabsContent value="general">
          <DescriptionList columns={2}>
            <DescriptionItem label="Tipo de grupo" value={group.stimulation_group_type?.name ?? '—'} />
            <DescriptionItem label="Centro de salud" value={group.health_center?.name ?? '—'} />
            <DescriptionItem label="Persona promotora" value={group.promoter?.name ?? '—'} />
            <DescriptionItem
              label="Estado"
              value={
                <StatusPill tone={group.is_active ? 'b' : 'n'}>{group.is_active ? 'Activo' : 'Inactivo'}</StatusPill>
              }
            />
            <DescriptionItem label="Fecha de inicio" value={displayFormatDate(group.start_date, 'mediumDate')} mono />
            <DescriptionItem
              label="Fecha de fin"
              value={group.end_date ? displayFormatDate(group.end_date, 'mediumDate') : '—'}
              mono
            />
          </DescriptionList>
        </TabsContent>

        <TabsContent value="members">
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <h3 className="text-sm font-semibold">Miembros</h3>
              <PermissionGuard permission="update.stimulation_group">
                <Button size="sm" onClick={openEnrollDialog} className="flex items-center gap-1.5">
                  <Plus className="h-4 w-4" />
                  Inscribir niño
                </Button>
              </PermissionGuard>
            </div>

            {isLoadingMembers ? (
              <InlineLoading name="stimulation-group-members" />
            ) : members.length === 0 ? (
              <InlineEmpty>Este grupo aún no tiene miembros.</InlineEmpty>
            ) : (
              <ul className="divide-y divide-border border border-border">
                {members.map((member) => (
                  <li
                    key={member.id}
                    className="flex flex-wrap items-center justify-between gap-2 px-4 py-3"
                  >
                    <span className="font-medium">{member.child ? childFullName(member.child) : '—'}</span>
                    <span className="text-xs text-muted-foreground">
                      {member.default_attendance_payment_status?.name ?? '—'}
                    </span>
                    <span className="font-mono text-xs tabular-nums text-muted-foreground">
                      Inscrito: {displayFormatDate(member.enrolled_at, 'mediumDate')}
                    </span>
                    <PermissionGuard permission="update.stimulation_group">
                      <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 text-destructive hover:text-destructive"
                        onClick={() => setMemberToRemove(member)}
                        aria-label="Dar de baja"
                      >
                        <Unlink className="size-4" />
                      </Button>
                    </PermissionGuard>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </TabsContent>
      </Tabs>

      <StimulationGroupFormModal
        isOpen={editOpen}
        mode={CrudMode.EDIT}
        group={group}
        onClose={() => setEditOpen(false)}
        onSaved={(updated) => setGroup(updated)}
      />

      <Modal
        isOpen={enrollOpen}
        onClose={() => setEnrollOpen(false)}
        title="Inscribir niño"
        footer={
          <>
            <Button variant="outline" onClick={() => setEnrollOpen(false)} disabled={isEnrolling}>
              Cancelar
            </Button>
            <Button onClick={() => void handleEnroll()} loading={isEnrolling}>
              {isEnrolling ? 'Inscribiendo…' : 'Inscribir'}
            </Button>
          </>
        }
      >
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor="enroll-child_id" required>
              Niño
            </FieldLabel>
            <SearchSelect<IChild>
              id="enroll-child_id"
              value={selectedChildId}
              onChange={(v) => setSelectedChildId((v as string | null) ?? null)}
              loadOptions={(term) => ChildResource.searchByTerm(term)}
              getOptionLabel={(c) => childFullName(c)}
              placeholder="Buscar niño…"
            />
          </Field>
          <Field>
            <FieldLabel htmlFor="enroll-status_id" required>
              Estatus de pago por defecto
            </FieldLabel>
            <SearchSelect<ICatalogItem>
              id="enroll-status_id"
              value={selectedStatusId}
              onChange={(v) => setSelectedStatusId((v as string | null) ?? null)}
              options={statusCatalogs[CatalogType.ATTENDANCE_PAYMENT_STATUS] ?? []}
              isLoading={isLoadingStatuses}
              placeholder="Seleccionar…"
            />
          </Field>
        </FieldGroup>
      </Modal>

      <ConfirmDialog
        isOpen={Boolean(memberToRemove)}
        variant="delete"
        entityName={memberToRemove?.child ? childFullName(memberToRemove.child) : undefined}
        onConfirm={handleRemoveConfirm}
        onCancel={() => setMemberToRemove(null)}
      />
    </div>
  )
}
