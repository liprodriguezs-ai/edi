import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Textarea } from '@/components/ui/textarea'
import type { IPackage } from '@/modules/package/schema'
import { PackageResource } from '@/modules/package/services/PackageResource'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Eye, MoreHorizontal, RefreshCcw, TriangleAlert, Undo2 } from 'lucide-react'
import { useCallback, useMemo, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { z } from 'zod'

// ── UUID constant — must match backend PackageStatus::READY ───────────────────
const PACKAGE_STATUS_READY = 'a2001001-0000-4000-8000-000000000002'

// ── Schemas ───────────────────────────────────────────────────────────────────

const reasonSchema = z.object({
  reason: z.string().min(1, 'El motivo es requerido'),
})
type ReasonValues = z.infer<typeof reasonSchema>

const reassignSchema = z.object({
  unit_id: z.string().min(1, 'La unidad de destino es requerida'),
})
type ReassignValues = z.infer<typeof reassignSchema>

// ── Shared reason dialog (return + incident share this shape) ─────────────────

interface ReasonDialogProps {
  isOpen: boolean
  title: string
  description: string
  submitLabel: string
  isPending: boolean
  onConfirm: (_reason: string) => void
  onCancel: () => void
}

function ReasonDialog({
  isOpen,
  title,
  description,
  submitLabel,
  isPending,
  onConfirm,
  onCancel,
}: ReasonDialogProps) {
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<ReasonValues>({
    resolver: zodResolver(reasonSchema),
  })

  const handleCancel = () => {
    reset()
    onCancel()
  }

  const doSubmit = handleSubmit(({ reason }) => {
    onConfirm(reason)
    reset()
  })

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && handleCancel()}>
      <DialogContent className="sm:max-w-[480px]">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor="reason-textarea" required>Motivo</FieldLabel>
            <Textarea
              id="reason-textarea"
              placeholder="Describe el motivo..."
              className="resize-none"
              rows={3}
              {...register('reason')}
              disabled={isPending}
            />
            <FieldError>{errors.reason?.message}</FieldError>
          </Field>
        </FieldGroup>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={handleCancel} disabled={isPending}>
            Cancelar
          </Button>
          <Button type="button" onClick={() => void doSubmit()} disabled={isPending}>
            {isPending ? 'Guardando...' : submitLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── Reassign unit dialog ──────────────────────────────────────────────────────

interface ReassignDialogProps {
  isOpen: boolean
  isPending: boolean
  unitOptions: { id: string; name: string }[]
  loadingUnits: boolean
  onConfirm: (_unitId: string) => void
  onCancel: () => void
}

function ReassignDialog({ isOpen, isPending, unitOptions, loadingUnits, onConfirm, onCancel }: ReassignDialogProps) {

  const {
    control,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<ReassignValues>({
    resolver: zodResolver(reassignSchema),
  })

  const handleCancel = () => {
    reset()
    onCancel()
  }

  const doSubmit = handleSubmit(({ unit_id }) => {
    onConfirm(unit_id)
    reset()
  })

  return (
    <Dialog
      open={isOpen}
      onOpenChange={(open) => {
        if (!open) handleCancel()
      }}
    >
      <DialogContent className="sm:max-w-[480px]">
        <DialogHeader>
          <DialogTitle>Reasignar paquete</DialogTitle>
          <DialogDescription>
            Transfiere este paquete a otra unidad del condominio.
          </DialogDescription>
        </DialogHeader>
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor="reassign-unit" required>Unidad de destino</FieldLabel>
            <Controller
              name="unit_id"
              control={control}
              render={({ field }) => (
                <SearchSelect
                  id="reassign-unit"
                  value={field.value || null}
                  onChange={(v) => field.onChange((v as string | null) ?? '')}
                  options={unitOptions}
                  placeholder="Buscar unidad..."
                  isDisabled={isPending}
                  isLoading={loadingUnits}
                  invalid={!!errors.unit_id}
                  isClearable
                />
              )}
            />
            <FieldError>{errors.unit_id?.message}</FieldError>
          </Field>
        </FieldGroup>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={handleCancel} disabled={isPending}>
            Cancelar
          </Button>
          <Button type="button" onClick={() => void doSubmit()} disabled={isPending}>
            {isPending ? 'Reasignando...' : 'Reasignar'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ── Main component ─────────────────────────────────────────────────────────────

interface PackageRowActionsProps {
  pkg: IPackage
  onSuccess: () => void
}

/**
 * Secondary row actions for a package.
 * "Ver detalle" is always available; return/incident/reassign only for ready packages.
 * Renders as a "⋮" dropdown alongside the primary "Entregar" button.
 */
export function PackageRowActions({ pkg, onSuccess }: PackageRowActionsProps) {
  const isReady = pkg.package_status_id === PACKAGE_STATUS_READY

  const [returnOpen, setReturnOpen] = useState(false)
  const [incidentOpen, setIncidentOpen] = useState(false)
  const [reassignOpen, setReassignOpen] = useState(false)

  // Lazy-load units for the reassign dialog. Called explicitly when the user opens
  // the reassign dialog item so units are ready before the dialog mounts.
  const { catalogs: unitCatalogs, enable: enableUnits, isLoading: loadingUnits } = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.UNIT]: { _sort: 'number' } }), []),
  )

  const unitOptions = useMemo(
    () =>
      (unitCatalogs[CatalogType.UNIT] ?? []).map((u) => ({
        id: u.id,
        name:
          (u.full_identifier as string | undefined) ??
          (u.number as string | undefined) ??
          u.id,
      })),
    [unitCatalogs],
  )

  const queryClient = useQueryClient()

  const handleSuccess = useCallback(() => {
    // Invalidate the dashboard widget count so it refetches immediately
    void queryClient.invalidateQueries({ queryKey: ['package-pending-count'] })
    onSuccess()
  }, [queryClient, onSuccess])

  const returnMutation = useMutation({
    mutationFn: (reason: string) => PackageResource.returnPackage(pkg.id, reason),
    onSuccess: () => {
      toast.success('Paquete marcado como devuelto')
      setReturnOpen(false)
      handleSuccess()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error).title
          : 'No se pudo completar la devolución',
      ),
  })

  const incidentMutation = useMutation({
    mutationFn: (reason: string) => PackageResource.incident(pkg.id, reason),
    onSuccess: () => {
      toast.success('Incidente reportado')
      setIncidentOpen(false)
      handleSuccess()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error).title
          : 'No se pudo reportar el incidente',
      ),
  })

  const reassignMutation = useMutation({
    mutationFn: (unitId: string) => PackageResource.reassign(pkg.id, unitId),
    onSuccess: () => {
      toast.success('Paquete reasignado correctamente')
      setReassignOpen(false)
      handleSuccess()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error).title
          : 'No se pudo reasignar el paquete',
      ),
  })

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            size="sm"
            variant="ghost"
            className="h-7 w-7 p-0"
            aria-label="Más acciones sobre el paquete"
          >
            <MoreHorizontal className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {/* Ver detalle: available for ALL packages regardless of status */}
          <DropdownMenuItem asChild>
            <Link to={`/package/${pkg.id}`} className="flex items-center">
              <Eye className="mr-2 size-4" />
              Ver detalle
            </Link>
          </DropdownMenuItem>
          {isReady && (
            <>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={() => setReturnOpen(true)}>
                <Undo2 className="mr-2 size-4" />
                Marcar como devuelto
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => setIncidentOpen(true)}>
                <TriangleAlert className="mr-2 size-4" />
                Reportar incidente
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={() => { enableUnits(); setReassignOpen(true) }}>
                <RefreshCcw className="mr-2 size-4" />
                Reasignar unidad
              </DropdownMenuItem>
            </>
          )}
        </DropdownMenuContent>
      </DropdownMenu>

      <ReasonDialog
        isOpen={returnOpen}
        title="Marcar como devuelto"
        description="El paquete será registrado como devuelto al transportista."
        submitLabel="Confirmar devolución"
        isPending={returnMutation.isPending}
        onConfirm={(reason) => returnMutation.mutate(reason)}
        onCancel={() => setReturnOpen(false)}
      />

      <ReasonDialog
        isOpen={incidentOpen}
        title="Reportar incidente"
        description="Registra el incidente ocurrido con este paquete."
        submitLabel="Reportar incidente"
        isPending={incidentMutation.isPending}
        onConfirm={(reason) => incidentMutation.mutate(reason)}
        onCancel={() => setIncidentOpen(false)}
      />

      <ReassignDialog
        isOpen={reassignOpen}
        isPending={reassignMutation.isPending}
        unitOptions={unitOptions}
        loadingUnits={loadingUnits}
        onConfirm={(unitId) => reassignMutation.mutate(unitId)}
        onCancel={() => setReassignOpen(false)}
      />
    </>
  )
}
