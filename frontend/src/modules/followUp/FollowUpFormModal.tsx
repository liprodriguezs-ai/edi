import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { childFullName } from '@/modules/child/schema'
import type { IChild } from '@/modules/child/schema'
import { ChildResource } from '@/modules/child/services/ChildResource'
import { followUpFormSchema, type FollowUpFormValues } from '@/modules/followUp/schema'
import { FollowUpResource } from '@/modules/followUp/services/FollowUpResource'
import { followUpErrorCode, followUpErrorMessage } from '@/modules/followUp/utils/followUpErrors'
import { apiClient } from '@/shared/api/apiClient'
import { Modal } from '@/shared/components/Modal'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'

interface CompletedScreeningOption {
  id: string
  visit_date?: string | null
}

interface FollowUpFormModalProps {
  onClose: () => void
  onCreated: () => void
}

const CATALOG_REQUEST = {
  [CatalogType.FOLLOW_UP_TYPE]: { _sort: 'sort_order' },
  [CatalogType.REFERRAL_SPECIALTY]: { _sort: 'name' },
}

/**
 * REQ-FUP-009/006 — manual create: child → completed screening → type →
 * due date. Catalogs load on open (not eagerly on the list page).
 */
export function FollowUpFormModal({ onClose, onCreated }: FollowUpFormModalProps) {
  const [isSaving, setIsSaving] = useState(false)
  const [selectedChild, setSelectedChild] = useState<IChild | null>(null)
  const [screeningOptions, setScreeningOptions] = useState<CompletedScreeningOption[]>([])
  const [isLoadingScreenings, setIsLoadingScreenings] = useState(false)

  const catalogs = useLazyCatalogs(useMemo(() => CATALOG_REQUEST, []))
  const { enable: enableCatalogs } = catalogs
  useEffect(() => {
    enableCatalogs()
  }, [enableCatalogs])

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors },
  } = useForm<FollowUpFormValues>({
    resolver: zodResolver(followUpFormSchema),
    defaultValues: { screening_id: '', follow_up_type_id: '', due_date: '', referral_specialty_id: '', notes: '' },
  })

  const followUpTypeId = watch('follow_up_type_id')
  const followUpTypes = catalogs.catalogs[CatalogType.FOLLOW_UP_TYPE] ?? []
  const specialties = catalogs.catalogs[CatalogType.REFERRAL_SPECIALTY] ?? []
  const selectedType = followUpTypes.find((t) => t.id === followUpTypeId)
  const isReferral = selectedType?.key === 'specialist_referral'

  const loadChildOptions = async (term: string) => ChildResource.searchByTerm(term)

  const handleChildChange = async (value: string | string[] | null) => {
    const id = Array.isArray(value) ? value[0] : value
    setValue('screening_id', '')

    if (!id) {
      setSelectedChild(null)
      setScreeningOptions([])
      return
    }

    setIsLoadingScreenings(true)
    try {
      const child = await ChildResource.show(id)
      setSelectedChild(child)

      const { data } = await apiClient.post<{
        data: Array<{ id: string; visit?: { visit_date?: string }; screening_status?: { key?: string } }>
      }>('/screening/search?limit=25', {
        filters: [{ field: 'child_id', operator: '=', value: id }],
        includes: [{ relation: 'screening_status' }, { relation: 'visit' }],
      })

      const completed = data.data
        .filter((s) => s.screening_status?.key === 'completed')
        .map((s) => ({ id: s.id, visit_date: s.visit?.visit_date ?? null }))

      setScreeningOptions(completed)
    } catch {
      toast.error('No se pudieron cargar las Pruebas EDI del niño')
    } finally {
      setIsLoadingScreenings(false)
    }
  }

  const doSubmit = handleSubmit(async (values) => {
    setIsSaving(true)
    try {
      await FollowUpResource.create(values)
      toast.success('Seguimiento creado')
      onCreated()
    } catch (err) {
      const code = followUpErrorCode(err)
      if (code === 'SCREENING_NOT_ELIGIBLE') {
        toast.error('El niño no tiene una Prueba EDI completada elegible.')
      } else if (code === 'FOLLOW_UP_DUPLICATE') {
        toast.error('Ya existe un seguimiento de este tipo para esa Prueba EDI.')
      } else {
        toast.error(followUpErrorMessage(err, 'No se pudo crear el seguimiento'))
      }
    } finally {
      setIsSaving(false)
    }
  })

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Nuevo seguimiento"
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose} disabled={isSaving}>
            Cancelar
          </Button>
          <Button type="button" onClick={() => void doSubmit()} loading={isSaving}>
            Crear seguimiento
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
            <FieldLabel required>Niño</FieldLabel>
            <SearchSelect<IChild>
              value={selectedChild?.id ?? null}
              onChange={handleChildChange}
              loadOptions={loadChildOptions}
              selectedOptions={selectedChild ? [selectedChild] : []}
              getOptionLabel={(child) => childFullName(child)}
              placeholder="Buscar niño…"
            />
          </Field>

          <Field>
            <FieldLabel htmlFor="follow-up-screening" required>
              Prueba EDI completada
            </FieldLabel>
            <Select
              value={watch('screening_id')}
              onValueChange={(value) => setValue('screening_id', value, { shouldValidate: true })}
              disabled={!selectedChild || isLoadingScreenings}
            >
              <SelectTrigger id="follow-up-screening">
                <SelectValue placeholder={isLoadingScreenings ? 'Cargando…' : 'Selecciona una Prueba EDI'} />
              </SelectTrigger>
              <SelectContent>
                {screeningOptions.map((option) => (
                  <SelectItem key={option.id} value={option.id}>
                    {option.visit_date ?? option.id}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldError>{errors.screening_id?.message}</FieldError>
          </Field>

          <Field>
            <FieldLabel htmlFor="follow-up-type" required>
              Tipo de seguimiento
            </FieldLabel>
            <Select
              value={followUpTypeId}
              onValueChange={(value) => setValue('follow_up_type_id', value, { shouldValidate: true })}
            >
              <SelectTrigger id="follow-up-type">
                <SelectValue placeholder="Selecciona un tipo" />
              </SelectTrigger>
              <SelectContent>
                {followUpTypes.map((type) => (
                  <SelectItem key={type.id} value={type.id}>
                    {type.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldError>{errors.follow_up_type_id?.message}</FieldError>
          </Field>

          {isReferral && (
            <Field>
              <FieldLabel htmlFor="follow-up-specialty">Especialidad sugerida</FieldLabel>
              <Select
                value={watch('referral_specialty_id') ?? ''}
                onValueChange={(value) => setValue('referral_specialty_id', value)}
              >
                <SelectTrigger id="follow-up-specialty">
                  <SelectValue placeholder="Opcional" />
                </SelectTrigger>
                <SelectContent>
                  {specialties.map((specialty) => (
                    <SelectItem key={specialty.id} value={specialty.id}>
                      {specialty.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>
          )}

          <Field>
            <FieldLabel htmlFor="follow-up-due-date" required>
              Fecha límite
            </FieldLabel>
            <Input id="follow-up-due-date" type="date" disabled={isSaving} {...register('due_date')} />
            <FieldError>{errors.due_date?.message}</FieldError>
          </Field>

          <Field>
            <FieldLabel htmlFor="follow-up-notes">Notas</FieldLabel>
            <Textarea id="follow-up-notes" rows={3} disabled={isSaving} {...register('notes')} />
          </Field>
        </FieldGroup>
      </form>
    </Modal>
  )
}
