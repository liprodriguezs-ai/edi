import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { CollaboratorFormModal } from '@/modules/collaborator/CollaboratorFormModal'
import { CollaboratorDataTab } from '@/modules/collaborator/components/CollaboratorDataTab'
import { CollaboratorProfileHeader } from '@/modules/collaborator/components/CollaboratorProfileHeader'
import { useCollaboratorDetail } from '@/modules/collaborator/hooks/useCollaboratorDetail'
import { CollaboratorResource } from '@/modules/collaborator/services/CollaboratorResource'
import type { ICollaborator } from '@/modules/collaborator/schema'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { CrudMode } from '@/shared/enums/CrudMode'
import { toast } from '@/shared/hooks/useToast'
import { useMutation } from '@tanstack/react-query'
import type { UseMutationResult } from '@tanstack/react-query'
import { useCallback, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'

interface CollaboratorDetailProps {
  collaboratorId: string
}

function toastApiError(err: unknown, fallback: string) {
  toast.error(err instanceof ApiError ? formatApiErrorMessage(err) : fallback)
}

function collaboratorDisplayName(collaborator: ICollaborator | null | undefined): string {
  return collaborator?.full_name ?? collaborator?.name ?? 'empleado'
}

interface TerminateDialogProps {
  open: boolean
  collaborator: ICollaborator | null | undefined
  terminationDate: string
  terminationReason: string
  mutation: UseMutationResult<unknown, unknown, void>
  onOpenChange: (_open: boolean) => void
  onDateChange: (_date: string) => void
  onReasonChange: (_reason: string) => void
  onClose: () => void
}

function TerminateDialog({
  open,
  collaborator,
  terminationDate,
  terminationReason,
  mutation,
  onOpenChange,
  onDateChange,
  onReasonChange,
  onClose,
}: TerminateDialogProps) {
  const isDisabled = mutation.isPending || terminationReason.trim().length === 0 || !terminationDate
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className='max-w-md p-0' aria-describedby={undefined}>
        <DialogHeader className='px-6 pt-6'>
          <DialogTitle>Dar de baja a {collaboratorDisplayName(collaborator)}</DialogTitle>
        </DialogHeader>
        <div className='space-y-4 px-6 py-2'>
          <Field>
            <FieldLabel htmlFor='term-date' required>
              Fecha de baja
            </FieldLabel>
            <Input
              id='term-date'
              type='date'
              value={terminationDate}
              onChange={(e) => onDateChange(e.target.value)}
            />
          </Field>
          <Field>
            <FieldLabel htmlFor='term-reason' required>
              Motivo
            </FieldLabel>
            <Textarea
              id='term-reason'
              rows={3}
              maxLength={255}
              value={terminationReason}
              onChange={(e) => onReasonChange(e.target.value)}
              placeholder='Motivo de la baja…'
              className='resize-none'
            />
          </Field>
        </div>
        <DialogFooter className='px-6 pb-6'>
          <Button variant='outline' onClick={onClose} disabled={mutation.isPending}>
            Volver
          </Button>
          <Button variant='destructive' disabled={isDisabled} onClick={() => mutation.mutate()}>
            {mutation.isPending ? 'Procesando…' : 'Dar de baja'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

interface ReactivateDialogProps {
  open: boolean
  collaborator: ICollaborator | null | undefined
  hireDate: string
  mutation: UseMutationResult<unknown, unknown, void>
  onOpenChange: (_open: boolean) => void
  onDateChange: (_date: string) => void
  onClose: () => void
}

function ReactivateDialog({
  open,
  collaborator,
  hireDate,
  mutation,
  onOpenChange,
  onDateChange,
  onClose,
}: ReactivateDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className='max-w-md p-0' aria-describedby={undefined}>
        <DialogHeader className='px-6 pt-6'>
          <DialogTitle>Reactivar a {collaboratorDisplayName(collaborator)}</DialogTitle>
        </DialogHeader>
        <div className='space-y-3 px-6 py-2'>
          <p className='text-[13px] text-[var(--ink-2)]'>
            Se creará un nuevo registro de empleo con los datos personales actuales. Podrás ajustar
            puesto, salario y turno después.
          </p>
          <Field>
            <FieldLabel htmlFor='rehire-date' required>
              Fecha de alta
            </FieldLabel>
            <Input
              id='rehire-date'
              type='date'
              value={hireDate}
              onChange={(e) => onDateChange(e.target.value)}
            />
          </Field>
        </div>
        <DialogFooter className='px-6 pb-6'>
          <Button variant='outline' onClick={onClose} disabled={mutation.isPending}>
            Volver
          </Button>
          <Button disabled={mutation.isPending || !hireDate} onClick={() => mutation.mutate()}>
            {mutation.isPending ? 'Procesando…' : 'Reactivar'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function CollaboratorDetail({ collaboratorId }: CollaboratorDetailProps) {
  const { collaborator, isLoading, error, notFound, refresh } =
    useCollaboratorDetail(collaboratorId)
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const initialTab = searchParams.get('tab') ?? 'datos'
  const [editModalOpen, setEditModalOpen] = useState(false)
  const today = useMemo(() => new Date().toISOString().slice(0, 10), [])
  const [terminateOpen, setTerminateOpen] = useState(false)
  const [terminationDate, setTerminationDate] = useState(today)
  const [terminationReason, setTerminationReason] = useState('')
  const [reactivateOpen, setReactivateOpen] = useState(false)
  const [hireDate, setHireDate] = useState(today)

  const handleEditSuccess = useCallback(
    (_result: ICollaborator) => {
      setEditModalOpen(false)
      refresh()
    },
    [refresh]
  )

  const handleEditOpen = useCallback(() => setEditModalOpen(true), [])
  const handleEditClose = useCallback(() => setEditModalOpen(false), [])
  const handleTerminateClose = useCallback(() => setTerminateOpen(false), [])
  const handleReactivateClose = useCallback(() => setReactivateOpen(false), [])
  const handleTerminateOpenChange = useCallback((open: boolean) => {
    if (!open) setTerminateOpen(false)
  }, [])
  const handleReactivateOpenChange = useCallback((open: boolean) => {
    if (!open) setReactivateOpen(false)
  }, [])

  const handleStartTerminate = useCallback(() => {
    setTerminationDate(today)
    setTerminationReason('')
    setTerminateOpen(true)
  }, [today])

  const handleStartReactivate = useCallback(() => {
    setHireDate(today)
    setReactivateOpen(true)
  }, [today])

  const terminateMutation = useMutation({
    mutationFn: () =>
      CollaboratorResource.terminate(collaboratorId, {
        termination_date: terminationDate,
        termination_reason: terminationReason.trim(),
      }),
    onSuccess: () => {
      toast.success('Empleado dado de baja')
      setTerminateOpen(false)
      setTerminationReason('')
      refresh()
    },
    onError: (err) => toastApiError(err, 'No se pudo dar de baja al empleado'),
  })

  const reactivateMutation = useMutation({
    mutationFn: () => CollaboratorResource.reactivate(collaboratorId, { hire_date: hireDate }),
    onSuccess: (created) => {
      toast.success('Empleado reactivado — se creó un nuevo registro')
      setReactivateOpen(false)
      navigate(`/collaborator/${(created as ICollaborator).id}`)
    },
    onError: (err) => toastApiError(err, 'No se pudo reactivar al empleado'),
  })

  if (error && !isLoading) {
    return (
      <EntityNotFound
        variant={notFound ? 'notFound' : 'error'}
        title={notFound ? 'Colaborador no encontrado' : undefined}
        backTo='/collaborator'
        backLabel='Volver a colaboradores'
        onRetry={notFound ? undefined : refresh}
      />
    )
  }

  return (
    <div className='space-y-6'>
      <CollaboratorProfileHeader
        collaborator={collaborator}
        isLoading={isLoading}
        onEdit={handleEditOpen}
        onTerminate={handleStartTerminate}
        onReactivate={handleStartReactivate}
      />

      <Tabs defaultValue={initialTab}>
        <TabsList className='w-full justify-start'>
          <TabsTrigger value='datos'>Datos</TabsTrigger>
        </TabsList>

        <TabsContent value='datos' className='mt-6'>
          <CollaboratorDataTab collaborator={collaborator} isLoading={isLoading} />
        </TabsContent>
      </Tabs>

      <CollaboratorFormModal
        isOpen={editModalOpen}
        mode={CrudMode.EDIT}
        entity={collaborator}
        onClose={handleEditClose}
        onSuccess={handleEditSuccess}
      />

      {/* Baja de empleado (§7.5): fecha + motivo; revoca acceso a la app */}
      <TerminateDialog
        open={terminateOpen}
        collaborator={collaborator}
        terminationDate={terminationDate}
        terminationReason={terminationReason}
        mutation={terminateMutation}
        onOpenChange={handleTerminateOpenChange}
        onDateChange={setTerminationDate}
        onReasonChange={setTerminationReason}
        onClose={handleTerminateClose}
      />

      {/* Reactivación (§7.5.2): crea un nuevo registro de empleo */}
      <ReactivateDialog
        open={reactivateOpen}
        collaborator={collaborator}
        hireDate={hireDate}
        mutation={reactivateMutation}
        onOpenChange={handleReactivateOpenChange}
        onDateChange={setHireDate}
        onClose={handleReactivateClose}
      />
    </div>
  )
}
