import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Modal } from '@/shared/components/Modal'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { visitFormSchema, type IVisit, type VisitFormValues } from '@/modules/visit/schema'
import { VisitResource } from '@/modules/visit/services/VisitResource'
import { CatalogType, useCatalogs, type ICatalogItem } from '@/shared/hooks/useCatalogs'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { todayLocalDateString } from '@/shared/utils/dateHelper'

interface VisitFormModalProps {
  isOpen: boolean
  childId: string
  onClose: () => void
  onCreated: (_visit: IVisit) => void
}

/**
 * REQ-CH-008 — nueva visita, siempre desde el detalle del niño (child
 * pre-bound, D12: no se busca al niño vía /selects). receipt_number lo
 * asigna el servidor; nunca se muestra ni envía como campo editable acá.
 */
export function VisitFormModal(props: VisitFormModalProps) {
  if (!props.isOpen) return null
  return <VisitFormSheet {...props} />
}

function VisitFormSheet({ childId, onClose, onCreated }: VisitFormModalProps) {
  const [isSaving, setIsSaving] = useState(false)

  const {
    register,
    handleSubmit,
    control,
    formState: { errors },
  } = useForm<VisitFormValues>({
    resolver: zodResolver(visitFormSchema),
    defaultValues: {
      child_id: childId,
      health_center_id: '',
      practitioner_id: '',
      physical_receipt_number: '',
      visit_date: todayLocalDateString(),
    },
  })

  const { catalogs, isLoading: isLoadingCatalogs } = useCatalogs({
    [CatalogType.HEALTH_CENTER]: { _sort: 'name' },
    [CatalogType.PRACTITIONER_PROFILE]: { _with: 'user' },
  })

  const doSubmit = handleSubmit(async (values) => {
    setIsSaving(true)
    try {
      const visit = await VisitResource.create(values)
      toast.success('Visita registrada')
      onCreated(visit)
      onClose()
    } catch (err) {
      if (err instanceof ApiError) {
        const { title, description } = formatApiErrorMessage(err)
        toast.error(title, description)
      } else {
        toast.error('No se pudo registrar la visita')
      }
    } finally {
      setIsSaving(false)
    }
  })

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Nueva visita"
      size="lg"
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose} disabled={isSaving} className="min-w-[80px]">
            Cancelar
          </Button>
          <Button type="button" onClick={() => void doSubmit()} loading={isSaving} className="min-w-[100px]">
            Registrar
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
          <div className="grid grid-cols-12 gap-4">
            <Field className="col-span-12">
              <FieldLabel htmlFor="visit-visit_date" required>
                Fecha de la visita
              </FieldLabel>
              <Input id="visit-visit_date" type="date" disabled={isSaving} {...register('visit_date')} />
              <FieldError>{errors.visit_date?.message}</FieldError>
            </Field>
            <Field className="col-span-12">
              <FieldLabel htmlFor="visit-health_center_id" required>
                Centro de salud
              </FieldLabel>
              <Controller
                name="health_center_id"
                control={control}
                render={({ field }) => (
                  <SearchSelect<ICatalogItem>
                    id="visit-health_center_id"
                    value={field.value || null}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={catalogs[CatalogType.HEALTH_CENTER] ?? []}
                    isDisabled={isSaving}
                    isLoading={isLoadingCatalogs}
                    invalid={Boolean(errors.health_center_id)}
                    placeholder="Seleccionar…"
                  />
                )}
              />
              <FieldError>{errors.health_center_id?.message}</FieldError>
            </Field>
            <Field className="col-span-12">
              <FieldLabel htmlFor="visit-practitioner_id" required>
                Profesional
              </FieldLabel>
              <Controller
                name="practitioner_id"
                control={control}
                render={({ field }) => (
                  <SearchSelect<ICatalogItem>
                    id="visit-practitioner_id"
                    value={field.value || null}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={catalogs[CatalogType.PRACTITIONER_PROFILE] ?? []}
                    isDisabled={isSaving}
                    isLoading={isLoadingCatalogs}
                    invalid={Boolean(errors.practitioner_id)}
                    placeholder="Seleccionar…"
                    getOptionLabel={(option) =>
                      (option as { user?: { name?: string } }).user?.name || option.name || ''
                    }
                  />
                )}
              />
              <FieldError>{errors.practitioner_id?.message}</FieldError>
            </Field>
            <Field className="col-span-12">
              <FieldLabel htmlFor="visit-physical_receipt_number">Folio físico (papel)</FieldLabel>
              <Input
                id="visit-physical_receipt_number"
                placeholder="Opcional"
                disabled={isSaving}
                {...register('physical_receipt_number')}
              />
              <FieldError>{errors.physical_receipt_number?.message}</FieldError>
            </Field>
          </div>
        </FieldGroup>
      </form>
    </Modal>
  )
}
