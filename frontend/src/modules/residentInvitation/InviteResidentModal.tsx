import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { useEmailCheck } from '@/modules/residentInvitation/hooks/useEmailCheck'
import {
  invitableRelationshipIds,
  inviteFormSchema,
  type InviteFormValues,
} from '@/modules/residentInvitation/schema'
import { ResidentInvitationResource } from '@/modules/residentInvitation/services/ResidentInvitationResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { usePermissions } from '@/shared/hooks/usePermissions'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'

interface InviteResidentModalProps {
  unitId: string
  unitLabel: string
  isOpen: boolean
  onClose: () => void
  /**
   * Tri-state vacancy signal for the target unit: `false` = confirmed vacant
   * (OWNER offered when the inviter also holds create.resident_invitation);
   * `true` or `undefined` (unknown/still loading) keeps OWNER hidden. Omit
   * this prop entirely for self-service mounts (e.g. MyUnitCard) — OWNER must
   * never appear there.
   */
  hasActiveOwner?: boolean
}

/**
 * Invitar residente por email (§ invitación de residentes). El propietario o el
 * admin envían el correo; el invitado activa su cuenta y queda dado de alta en
 * la unidad. El aviso "ya está en otra unidad" NO bloquea el envío.
 */
export function InviteResidentModal({
  unitId,
  unitLabel,
  isOpen,
  onClose,
  hasActiveOwner,
}: InviteResidentModalProps) {
  const queryClient = useQueryClient()
  const canManageInvitations = usePermissions('create.resident_invitation')

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors },
  } = useForm<InviteFormValues>({
    resolver: zodResolver(inviteFormSchema),
    defaultValues: { email: '', unit_relationship_id: '' },
  })

  useEffect(() => {
    if (isOpen) reset({ email: '', unit_relationship_id: '' })
  }, [isOpen, reset])

  const { catalogs } = useCatalogs(
    useMemo(() => ({ [CatalogType.UNIT_RELATIONSHIP]: { _sort: 'order' } }), [])
  )

  const relationshipOptions = useMemo(() => {
    const invitableIds = invitableRelationshipIds({ canManageInvitations, hasActiveOwner })

    return (catalogs[CatalogType.UNIT_RELATIONSHIP] ?? []).filter((rel) => invitableIds.has(rel.id))
  }, [catalogs, canManageInvitations, hasActiveOwner])

  const email = useWatch({ control, name: 'email' }) ?? ''
  const emailCheck = useEmailCheck(email, unitId)
  const check = emailCheck.data

  const alreadyInThisUnit = check?.already_in_this_unit ?? false
  const otherUnitLabels = (check?.same_tenant_units ?? [])
    .map((u) => u.unit_label)
    .filter(Boolean)
    .join(', ')

  const mutation = useMutation({
    mutationFn: (values: InviteFormValues) => ResidentInvitationResource.invite(unitId, values),
    onSuccess: ({ invitation }) => {
      const expires = invitation.expires_at
        ? ` Vence el ${displayFormatDate(invitation.expires_at, 'mediumDate')}.`
        : ''
      toast.success({
        title: `Invitación enviada a ${invitation.email}`,
        description: `${expires} La persona recibirá un correo para crear su cuenta.`.trim(),
      })
      void queryClient.invalidateQueries({ queryKey: ['unit', 'invitations', unitId] })
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo enviar la invitación'
      )
    },
  })

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side='right'
        className='flex flex-col gap-0 p-0 w-full sm:max-w-xl'
        aria-describedby={undefined}
      >
        <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
          <SheetTitle>Invitar residente</SheetTitle>
          <p className='text-sm text-muted-foreground'>
            Le enviaremos un correo a la persona para que cree su cuenta y se una a {unitLabel}. La
            invitación vence en 7 días.
          </p>
        </SheetHeader>

        <form
          className='min-h-0 flex-1 overflow-y-auto px-6 py-2'
          onSubmit={handleSubmit((values) => mutation.mutate(values))}
        >
          <FieldGroup>
            <RequiredFieldsLegend />
            <Field>
              <FieldLabel htmlFor='invite-email' required>
                Correo de la persona
              </FieldLabel>
              <Input
                id='invite-email'
                type='email'
                inputMode='email'
                autoComplete='off'
                placeholder='nombre@correo.com'
                aria-invalid={!!errors.email}
                {...register('email')}
                disabled={mutation.isPending}
              />
              <FieldDescription>A esta dirección llegará el enlace para unirse.</FieldDescription>
              <FieldError>{errors.email?.message}</FieldError>

              <div aria-live='polite' role='status' className='empty:hidden'>
                {alreadyInThisUnit && (
                  <p className='mt-1 rounded-md bg-destructive/10 px-3 py-2 text-[13px] text-destructive'>
                    Esta persona ya es residente de esta unidad.
                  </p>
                )}
                {!alreadyInThisUnit && check?.same_tenant_other_unit && (
                  <p className='mt-1 rounded-md bg-amber-500/10 px-3 py-2 text-[13px] text-amber-700'>
                    {otherUnitLabels
                      ? `Esta persona ya es residente de ${otherUnitLabels} en este condominio. Puedes invitarla igual: también quedará asociada a ${unitLabel}.`
                      : `Esta persona ya es residente de otra unidad en este condominio. Puedes invitarla igual: también quedará asociada a ${unitLabel}.`}
                  </p>
                )}
                {!alreadyInThisUnit && check?.has_pending_invitation && (
                  <p className='mt-1 rounded-md bg-muted px-3 py-2 text-[13px] text-muted-foreground'>
                    Ya hay una invitación pendiente para este correo en esta unidad. Puedes
                    reenviarla desde la lista de abajo.
                  </p>
                )}
              </div>
            </Field>

            <Field>
              <FieldLabel htmlFor='invite-relationship' required>
                Tipo de residente
              </FieldLabel>
              <Controller
                name='unit_relationship_id'
                control={control}
                render={({ field }) => (
                  <SearchSelect
                    id='invite-relationship'
                    value={field.value}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={relationshipOptions}
                    isDisabled={mutation.isPending}
                    invalid={!!errors.unit_relationship_id}
                    placeholder='Elige el tipo…'
                  />
                )}
              />
              <FieldError>{errors.unit_relationship_id?.message}</FieldError>
            </Field>
          </FieldGroup>
        </form>

        <SheetFooter className='shrink-0 px-6 pb-6 pt-4 border-t border-border flex-row justify-end gap-2'>
          <Button type='button' variant='outline' onClick={onClose} disabled={mutation.isPending}>
            Cancelar
          </Button>
          <Button
            type='button'
            onClick={handleSubmit((values) => mutation.mutate(values))}
            disabled={mutation.isPending || alreadyInThisUnit}
            className='min-w-[150px]'
          >
            {mutation.isPending ? 'Enviando…' : 'Enviar invitación'}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
