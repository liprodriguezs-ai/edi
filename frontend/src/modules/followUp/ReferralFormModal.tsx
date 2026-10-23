import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { referralFormSchema, type ReferralFormValues } from '@/modules/followUp/schema'
import { ReferralResource } from '@/modules/followUp/services/ReferralResource'
import { followUpErrorMessage } from '@/modules/followUp/utils/followUpErrors'
import { Modal } from '@/shared/components/Modal'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { todayLocalDateString } from '@/shared/utils/dateHelper'

interface ReferralFormModalProps {
  followUpId: string
  onClose: () => void
  onCreated: () => void
}

const CATALOG_REQUEST = { [CatalogType.REFERRAL_SPECIALTY]: { _sort: 'name' } }

/** REQ-FUP-007 — catalogs load on open, not eagerly. */
export function ReferralFormModal({ followUpId, onClose, onCreated }: ReferralFormModalProps) {
  const [isSaving, setIsSaving] = useState(false)
  const { catalogs, isLoading: catalogsLoading, enable } = useLazyCatalogs(useMemo(() => CATALOG_REQUEST, []))

  useEffect(() => {
    enable()
  }, [enable])

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<ReferralFormValues>({
    resolver: zodResolver(referralFormSchema),
    defaultValues: {
      follow_up_id: followUpId,
      referral_specialty_id: '',
      referred_at: todayLocalDateString(),
      institution: '',
      outcome_notes: '',
    },
  })

  const specialties = catalogs[CatalogType.REFERRAL_SPECIALTY] ?? []

  const doSubmit = handleSubmit(async (values) => {
    setIsSaving(true)
    try {
      await ReferralResource.create(values)
      toast.success('Referencia registrada')
      onCreated()
    } catch (err) {
      toast.error(followUpErrorMessage(err, 'No se pudo registrar la referencia'))
    } finally {
      setIsSaving(false)
    }
  })

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Registrar referencia"
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose} disabled={isSaving}>
            Cancelar
          </Button>
          <Button type="button" onClick={() => void doSubmit()} loading={isSaving}>
            Registrar referencia
          </Button>
        </>
      }
    >
      <form
        onSubmit={(e) => {
          e.preventDefault()
          void doSubmit(e)
        }}
      >
        <RequiredFieldsLegend />
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor="referral-specialty" required>
              Especialidad
            </FieldLabel>
            <Select
              value={watch('referral_specialty_id')}
              onValueChange={(value) => setValue('referral_specialty_id', value, { shouldValidate: true })}
              disabled={catalogsLoading}
            >
              <SelectTrigger id="referral-specialty">
                <SelectValue placeholder={catalogsLoading ? 'Cargando…' : 'Selecciona una especialidad'} />
              </SelectTrigger>
              <SelectContent>
                {specialties.map((specialty) => (
                  <SelectItem key={specialty.id} value={specialty.id}>
                    {specialty.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldError>{errors.referral_specialty_id?.message}</FieldError>
          </Field>

          <Field>
            <FieldLabel htmlFor="referral-date" required>
              Fecha de referencia
            </FieldLabel>
            <Input id="referral-date" type="date" disabled={isSaving} {...register('referred_at')} />
            <FieldError>{errors.referred_at?.message}</FieldError>
          </Field>

          <Field>
            <FieldLabel htmlFor="referral-institution">Institución</FieldLabel>
            <Input id="referral-institution" disabled={isSaving} {...register('institution')} />
          </Field>

          <Field>
            <FieldLabel htmlFor="referral-notes">Notas de resultado</FieldLabel>
            <Textarea id="referral-notes" rows={3} disabled={isSaving} {...register('outcome_notes')} />
          </Field>
        </FieldGroup>
      </form>
    </Modal>
  )
}
