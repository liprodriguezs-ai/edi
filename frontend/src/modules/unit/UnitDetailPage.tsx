import { Button } from '@/components/ui/button'
import { CatalogPill } from '@/shared/components/CatalogPill'
import { BlockResidentModal } from '@/modules/residentInvitation/BlockResidentModal'
import { InviteResidentModal } from '@/modules/residentInvitation/InviteResidentModal'
import { PendingInvitationsList } from '@/modules/residentInvitation/PendingInvitationsList'
import { UnitFormModal } from '@/modules/unit/UnitFormModal'
import { AssociatePersonModal } from '@/modules/unit/components/AssociatePersonModal'
import { ChangeOwnerDialog } from '@/modules/unit/components/ChangeOwnerDialog'
import { UNIT_RELATIONSHIP, type IUnit, type IUnitUser } from '@/modules/unit/schema'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { UnitUserResource } from '@/modules/unit/services/UnitUserResource'
import { VehicleFormModalStandalone } from '@/modules/unit/components/VehicleFormModalStandalone'
import { useSession } from '@/shared/auth/useSession'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Ban, Car, Pencil, Plus, Repeat2, UserPlus, UserRound } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { CrudMode } from '@/shared/enums/CrudMode'

const CARD = 'overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'
const CARD_HEAD = 'flex items-center justify-between border-b border-[var(--line-2)] px-4 py-3.5'

// ─── pure helpers ────────────────────────────────────────────────────────────

function formatEndAssocError(error: unknown): string {
  if (error instanceof ApiError) return formatApiErrorMessage(error).title
  return 'No se pudo finalizar la asociación'
}

function buildVehicleLabel(vehicle: {
  make?: string | null
  model?: string | null
  year?: string | number | null
}): string {
  return [vehicle.make, vehicle.model, vehicle.year].filter(Boolean).join(' ') || 'Vehículo'
}

function buildAssocSubtitle(assoc: IUnitUser): string {
  const parts: string[] = [assoc.unit_relationship?.name ?? '—']
  if (assoc.is_billing_owner) parts.push('Titular de pago')
  if (assoc.is_legal_representative) parts.push('Responsable legal')
  if (assoc.start_date) parts.push(`Desde ${displayFormatDate(assoc.start_date, 'mediumDate')}`)
  return parts.join(' · ')
}

function buildUnitSubtitle(unit: IUnit): string {
  return [unit.unit_type?.name, unit.section?.name, unit.tower?.name].filter(Boolean).join(' · ')
}

function buildConfirmDescription(assoc: IUnitUser | null): string {
  const name = assoc && assoc.user ? assoc.user.name : 'la persona'
  return `Se cerrará la asociación de ${name ?? 'la persona'} con fecha de hoy. El registro queda en el histórico.`
}

function formatM2(value: number | string | null | undefined): string {
  if (value == null) return '—'
  return Number(value).toFixed(2)
}

function formatCharacteristics(list: string[] | null | undefined): string {
  if (!list || list.length === 0) return '—'
  return list.join(', ')
}

// ─── sub-components ──────────────────────────────────────────────────────────

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className='flex justify-between gap-4 border-b border-[var(--line-2)] px-4 py-2.5 text-[13px] last:border-b-0'>
      <span className='text-[var(--ink-3)]'>{label}</span>
      <span className='text-right font-medium text-foreground'>{value ?? '—'}</span>
    </div>
  )
}

interface AssocCardProps {
  assoc: IUnitUser
  canEdit: boolean
  canBlock: boolean
  onEdit: (assoc: IUnitUser) => void
  onEnd: (assoc: IUnitUser) => void
  onBlock: (assoc: IUnitUser) => void
}

function AssocCard({ assoc, canEdit, canBlock, onEdit, onEnd, onBlock }: AssocCardProps) {
  const subtitle = buildAssocSubtitle(assoc)
  const hasContact = Boolean(assoc.user?.phone || assoc.user?.email)
  const contactLine = [assoc.user?.phone, assoc.user?.email].filter(Boolean).join(' · ')

  return (
    <div className='flex items-start gap-3 border-b border-[var(--line-2)] px-4 py-3 last:border-b-0'>
      <span className='grid size-9 flex-none place-items-center rounded-full bg-[var(--green-bg)] text-[var(--green)]'>
        <UserRound className='size-4' />
      </span>
      <div className='min-w-0 flex-1'>
        <p className='text-[13.5px] font-semibold'>{assoc.user?.name ?? '—'}</p>
        <p className='text-[12.5px] text-[var(--ink-3)]'>{subtitle}</p>
        {hasContact && <p className='text-[12px] text-[var(--ink-4)]'>{contactLine}</p>}
      </div>
      {(canEdit || canBlock) && (
        <div className='flex flex-none gap-1'>
          {canEdit && (
            <>
              <Button
                variant='ghost'
                size='sm'
                className='h-7 px-2 text-[12px]'
                onClick={() => onEdit(assoc)}
              >
                Editar
              </Button>
              <Button
                variant='ghost'
                size='sm'
                className='h-7 px-2 text-[12px] text-destructive hover:text-destructive'
                onClick={() => onEnd(assoc)}
              >
                Finalizar
              </Button>
            </>
          )}
          {canBlock && assoc.user?.id && (
            <Button
              variant='ghost'
              size='sm'
              className='h-7 px-2 text-[12px] text-destructive hover:text-destructive'
              onClick={() => onBlock(assoc)}
            >
              <Ban className='size-3.5' />
              Bloquear
            </Button>
          )}
        </div>
      )}
    </div>
  )
}

interface VehicleRowProps {
  vehicle: {
    id: string
    make?: string | null
    model?: string | null
    year?: string | number | null
    color?: string | null
    plate?: string | null
  }
}

function VehicleRow({ vehicle }: VehicleRowProps) {
  return (
    <div className='flex items-center gap-3 border-b border-[var(--line-2)] px-4 py-3 text-[13px] last:border-b-0'>
      <Car className='size-4 flex-none text-[var(--ink-3)]' aria-hidden />
      <span className='font-medium'>{buildVehicleLabel(vehicle)}</span>
      <span className='text-[var(--ink-3)]'>{vehicle.color ?? ''}</span>
      <span className='ml-auto rounded-md border border-border bg-[var(--surface-2)] px-2 py-0.5 font-mono text-[12px]'>
        {vehicle.plate}
      </span>
    </div>
  )
}

interface GeneralInfoCardProps {
  unit: IUnit
}

function GeneralInfoCard({ unit }: GeneralInfoCardProps) {
  return (
    <div className={CARD}>
      <div className={CARD_HEAD}>
        <h3 className='text-sm font-semibold'>Información general</h3>
      </div>
      <InfoRow label='Tipo' value={unit.unit_type?.name} />
      <InfoRow label='Número' value={unit.number} />
      {unit.interior_number && <InfoRow label='Número interior' value={unit.interior_number} />}
      {unit.street && <InfoRow label='Calle' value={unit.street} />}
      {unit.tower?.name && <InfoRow label='Torre' value={unit.tower.name} />}
      {unit.floor && <InfoRow label='Piso' value={unit.floor} />}
      <InfoRow label='M² construcción' value={formatM2(unit.built_m2)} />
      <InfoRow label='M² terreno' value={formatM2(unit.land_m2)} />
      <InfoRow label='Características' value={formatCharacteristics(unit.characteristics)} />
      {unit.notes && <InfoRow label='Notas' value={unit.notes} />}
    </div>
  )
}

interface PeopleCardProps {
  unitId: string
  activePeople: IUnitUser[]
  pastPeople: IUnitUser[]
  canEditUnitUser: boolean
  canBlock: boolean
  canManageInvitations: boolean
  currentUserId: string | null
  onAddPerson: () => void
  onInvite: () => void
  onEditPerson: (assoc: IUnitUser) => void
  onEndAssoc: (assoc: IUnitUser) => void
  onBlock: (assoc: IUnitUser) => void
}

function PeopleCard({
  unitId,
  activePeople,
  pastPeople,
  canEditUnitUser,
  canBlock,
  canManageInvitations,
  currentUserId,
  onAddPerson,
  onInvite,
  onEditPerson,
  onEndAssoc,
  onBlock,
}: PeopleCardProps) {
  const historicNames = pastPeople
    .map((p) => p.user?.name)
    .filter(Boolean)
    .join(', ')
  return (
    <div className={CARD}>
      <div className={CARD_HEAD}>
        <h3 className='text-sm font-semibold'>Personas asociadas</h3>
        <div className='flex items-center gap-1.5'>
          <PermissionGuard permission='create.resident_invitation'>
            <Button size='sm' className='h-8 rounded-full text-[12.5px]' onClick={onInvite}>
              <UserPlus className='size-3.5' />
              Invitar residente
            </Button>
          </PermissionGuard>
          <PermissionGuard permission='create.unit_user'>
            <Button
              size='sm'
              variant='outline'
              className='h-8 rounded-full text-[12.5px]'
              onClick={onAddPerson}
            >
              <Plus className='size-3.5' />
              Asociar persona
            </Button>
          </PermissionGuard>
        </div>
      </div>
      {activePeople.length === 0 ? (
        <p className='px-4 py-6 text-center text-[13px] text-[var(--ink-3)]'>
          Sin personas asociadas. Agrega al propietario para comenzar.
        </p>
      ) : (
        activePeople.map((assoc) => (
          <AssocCard
            key={assoc.id}
            assoc={assoc}
            canEdit={canEditUnitUser}
            canBlock={canBlock && assoc.user?.id !== currentUserId}
            onEdit={onEditPerson}
            onEnd={onEndAssoc}
            onBlock={onBlock}
          />
        ))
      )}
      <PendingInvitationsList unitId={unitId} canManage={canManageInvitations} />
      {pastPeople.length > 0 && (
        <div className='border-t border-border bg-[var(--surface-2)] px-4 py-2.5 text-[12px] text-[var(--ink-3)]'>
          Histórico: {historicNames}
        </div>
      )}
    </div>
  )
}

interface VehiclesCardProps {
  vehicles: IUnit['vehicles']
  onAddVehicle: () => void
}

function VehiclesCard({ vehicles, onAddVehicle }: VehiclesCardProps) {
  const hasVehicles = (vehicles?.length ?? 0) > 0
  return (
    <div className={CARD}>
      <div className={CARD_HEAD}>
        <h3 className='text-sm font-semibold'>Vehículos</h3>
        <PermissionGuard permission='create.vehicle'>
          <Button
            size='sm'
            variant='outline'
            className='h-8 rounded-full text-[12.5px]'
            onClick={onAddVehicle}
          >
            <Plus className='size-3.5' />
            Registrar vehículo
          </Button>
        </PermissionGuard>
      </div>
      {!hasVehicles ? (
        <p className='px-4 py-5 text-center text-[13px] text-[var(--ink-3)]'>
          Sin vehículos registrados.
        </p>
      ) : (
        vehicles!.map((vehicle) => <VehicleRow key={vehicle.id} vehicle={vehicle} />)
      )}
    </div>
  )
}

// ─── types ────────────────────────────────────────────────────────────────────

interface PersonModalState {
  open: boolean
  entity: IUnitUser | null
}

// ─── custom hook ─────────────────────────────────────────────────────────────

function useUnitDetailPage(id: string) {
  const queryClient = useQueryClient()

  const [editOpen, setEditOpen] = useState(false)
  const [personModal, setPersonModal] = useState<PersonModalState>({ open: false, entity: null })
  const [changeOwnerOpen, setChangeOwnerOpen] = useState(false)
  const [vehicleModalOpen, setVehicleModalOpen] = useState(false)
  const [assocToEnd, setAssocToEnd] = useState<IUnitUser | null>(null)
  const [inviteOpen, setInviteOpen] = useState(false)
  const [blockTarget, setBlockTarget] = useState<IUnitUser | null>(null)

  const canEditUnitUser = usePermissions('update.unit_user')
  const canBlock = usePermissions('block.resident')
  const canManageInvitations = usePermissions('create.resident_invitation')
  const { profile } = useSession()
  const currentUserId = profile?.user?.id ?? null

  const {
    entity: unit,
    isLoading,
    isError,
    isSuccess,
    notFound,
    refetch,
  } = useEntityDetail<IUnit>({
    queryKey: ['unit', 'detail', id],
    queryFn: () =>
      UnitResource.show(id, { include: 'unitType,unitStatus,tower,section,vehicles' } as never),
    enabled: Boolean(id),
  })

  const { data: people = [], isSuccess: peopleLoaded } = useQuery({
    queryKey: ['unit', 'people', id],
    queryFn: async () => {
      const { data } = await UnitUserResource.search({
        filters: [{ field: 'unit_id', operator: '=', value: id }],
        includes: [{ relation: 'user' }, { relation: 'unitRelationship' }],
        sort: [{ field: 'start_date', direction: 'desc' }],
      })
      return data
    },
    enabled: Boolean(id) && isSuccess,
  })

  const invalidateUnit = () => void queryClient.invalidateQueries({ queryKey: ['unit'] })

  const endAssociation = useMutation({
    mutationFn: (assoc: IUnitUser) =>
      UnitUserResource.update(assoc.id, {
        end_date: new Date().toISOString().slice(0, 10),
      } as never),
    onSuccess: () => {
      toast.success('Asociación finalizada — queda en el histórico')
      invalidateUnit()
      setAssocToEnd(null)
    },
    onError: (error) => toast.error(formatEndAssocError(error)),
  })

  const activePeople = people.filter((p) => !p.end_date)
  const pastPeople = people.filter((p) => Boolean(p.end_date))
  const currentOwner =
    activePeople.find((p) => p.unit_relationship_id === UNIT_RELATIONSHIP.OWNER) ?? null

  return {
    unit,
    isLoading,
    isError,
    notFound,
    refetch,
    activePeople,
    pastPeople,
    currentOwner,
    peopleLoaded,
    canEditUnitUser,
    canBlock,
    canManageInvitations,
    currentUserId,
    assocToEnd,
    setAssocToEnd,
    endAssociation,
    editOpen,
    setEditOpen,
    personModal,
    setPersonModal,
    changeOwnerOpen,
    setChangeOwnerOpen,
    vehicleModalOpen,
    setVehicleModalOpen,
    inviteOpen,
    setInviteOpen,
    blockTarget,
    setBlockTarget,
    invalidateUnit,
  }
}

// ─── page ─────────────────────────────────────────────────────────────────────

export const UnitDetailPage = () => {
  const { id = '' } = useParams<{ id: string }>()
  const {
    unit,
    isLoading,
    isError,
    notFound,
    refetch,
    activePeople,
    pastPeople,
    currentOwner,
    peopleLoaded,
    canEditUnitUser,
    canBlock,
    canManageInvitations,
    currentUserId,
    assocToEnd,
    setAssocToEnd,
    endAssociation,
    editOpen,
    setEditOpen,
    personModal,
    setPersonModal,
    changeOwnerOpen,
    setChangeOwnerOpen,
    vehicleModalOpen,
    setVehicleModalOpen,
    inviteOpen,
    setInviteOpen,
    blockTarget,
    setBlockTarget,
    invalidateUnit,
  } = useUnitDetailPage(id)

  if (isLoading) {
    return <p className='py-16 text-center text-sm text-[var(--ink-3)]'>Cargando unidad…</p>
  }

  if (isError || !unit) {
    return (
      <EntityNotFound
        variant={notFound ? 'notFound' : 'error'}
        title={notFound ? 'Unidad no encontrada' : undefined}
        backTo='/unit'
        backLabel='Volver a unidades'
        onRetry={notFound ? undefined : refetch}
      />
    )
  }

  const label = unit.full_identifier ?? unit.number ?? 'Unidad'

  return (
    <div className='space-y-5'>
      {/* encabezado */}
      <div className='flex flex-wrap items-start justify-between gap-4'>
        <div>
          <Link
            to='/unit'
            className='mb-1 inline-flex items-center gap-1 text-[12.5px] text-[var(--ink-3)] hover:text-foreground'
          >
            <ArrowLeft className='size-3.5' />
            Unidades
          </Link>
          <h1 className='flex items-center gap-3 text-[26px] font-semibold tracking-[-0.02em]'>
            {label}
            <CatalogPill name={unit.unit_status?.name} color={unit.unit_status?.color} />
          </h1>
          <p className='mt-1 text-[13.5px] text-[var(--ink-3)]'>{buildUnitSubtitle(unit)}</p>
        </div>
        <div className='flex items-center gap-2'>
          <PermissionGuard permission='change_owner.unit'>
            <Button
              variant='outline'
              className='rounded-full'
              onClick={() => setChangeOwnerOpen(true)}
            >
              <Repeat2 className='size-4' />
              Cambiar propietario
            </Button>
          </PermissionGuard>
          <PermissionGuard permission='update.unit'>
            <Button className='rounded-full' onClick={() => setEditOpen(true)}>
              <Pencil className='size-4' />
              Editar
            </Button>
          </PermissionGuard>
        </div>
      </div>

      <div className='grid grid-cols-1 gap-4 lg:grid-cols-2'>
        <GeneralInfoCard unit={unit} />
        <PeopleCard
          unitId={id}
          activePeople={activePeople}
          pastPeople={pastPeople}
          canEditUnitUser={canEditUnitUser}
          canBlock={canBlock}
          canManageInvitations={canManageInvitations}
          currentUserId={currentUserId}
          onAddPerson={() => setPersonModal({ open: true, entity: null })}
          onInvite={() => setInviteOpen(true)}
          onEditPerson={(a) => setPersonModal({ open: true, entity: a })}
          onEndAssoc={setAssocToEnd}
          onBlock={setBlockTarget}
        />
      </div>

      <VehiclesCard vehicles={unit.vehicles} onAddVehicle={() => setVehicleModalOpen(true)} />

      {/* modales */}
      <UnitFormModal
        isOpen={editOpen}
        mode={CrudMode.EDIT}
        entity={unit}
        onClose={() => setEditOpen(false)}
        onSuccess={() => {
          setEditOpen(false)
          invalidateUnit()
        }}
      />
      <AssociatePersonModal
        unitId={id}
        unitLabel={label}
        entity={personModal.entity}
        isOpen={personModal.open}
        onClose={() => setPersonModal({ open: false, entity: null })}
      />
      <InviteResidentModal
        unitId={id}
        unitLabel={label}
        isOpen={inviteOpen}
        onClose={() => setInviteOpen(false)}
        hasActiveOwner={peopleLoaded ? currentOwner != null : undefined}
      />
      <BlockResidentModal
        userId={blockTarget?.user?.id ?? null}
        userName={blockTarget?.user?.name}
        isOpen={blockTarget !== null}
        onClose={() => setBlockTarget(null)}
      />
      <ChangeOwnerDialog
        unitId={id}
        unitLabel={label}
        currentOwner={currentOwner}
        isOpen={changeOwnerOpen}
        onClose={() => setChangeOwnerOpen(false)}
      />
      <VehicleFormModalStandalone
        unitId={id}
        isOpen={vehicleModalOpen}
        onClose={() => setVehicleModalOpen(false)}
        onSaved={invalidateUnit}
      />
      <ConfirmDialog
        isOpen={Boolean(assocToEnd)}
        variant='delete'
        title='Finalizar asociación'
        description={buildConfirmDescription(assocToEnd)}
        confirmLabel='Finalizar'
        isLoading={endAssociation.isPending}
        onConfirm={() => assocToEnd && endAssociation.mutate(assocToEnd)}
        onCancel={() => setAssocToEnd(null)}
      />
    </div>
  )
}
