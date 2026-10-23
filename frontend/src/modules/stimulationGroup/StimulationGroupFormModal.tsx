import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Modal } from '@/shared/components/Modal'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import {
  stimulationGroupFormSchema,
  type IStimulationGroup,
  type StimulationGroupFormValues,
} from '@/modules/stimulationGroup/schema'
import { StimulationGroupResource } from '@/modules/stimulationGroup/services/StimulationGroupResource'
import { useStimulationGroupStore } from '@/modules/stimulationGroup/store/stimulationGroupStore'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { CatalogType, useCatalogs, type ICatalogItem } from '@/shared/hooks/useCatalogs'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo } from 'react'
import { Controller, useForm, type DefaultValues } from 'react-hook-form'
import { todayLocalDateString } from '@/shared/utils/dateHelper'

interface StimulationGroupFormModalProps {
  isOpen: boolean
  mode: CrudMode
  group: IStimulationGroup | null
  onClose: () => void
  onSaved: (_group: IStimulationGroup) => void
}

/**
 * REQ-SG-001 — alta/edición de grupo. Catálogos (tipo de grupo, centro de
 * salud, promotor) se piden solo mientras el panel está abierto.
 */
export function StimulationGroupFormModal(props: StimulationGroupFormModalProps) {
  if (!props.isOpen) return null
  return <StimulationGroupFormSheet {...props} />
}

function StimulationGroupFormSheet({ mode, group, onClose, onSaved }: StimulationGroupFormModalProps) {
  const isSaving = useStimulationGroupStore((s) => s.isSaving)

  const crud = useBaseCrud<IStimulationGroup, StimulationGroupFormValues>({
    store: useStimulationGroupStore,
    resource: { save: StimulationGroupResource.create, update: StimulationGroupResource.update },
    mode,
    entityId: group?.id,
    initialData: group,
  })

  const defaultValues = useMemo<StimulationGroupFormValues>(
    () => ({
      name: group?.name ?? '',
      stimulation_group_type_id: group?.stimulation_group_type_id ?? '',
      health_center_id: group?.health_center_id ?? '',
      promoter_user_id: group?.promoter_user_id ?? '',
      start_date: group?.start_date ?? todayLocalDateString(),
      end_date: group?.end_date ?? '',
      is_active: group?.is_active ?? true,
    }),
    [group]
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors },
  } = useForm<StimulationGroupFormValues>({
    resolver: zodResolver(stimulationGroupFormSchema),
    defaultValues: defaultValues as DefaultValues<StimulationGroupFormValues>,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const { catalogs, isLoading: isLoadingCatalogs } = useCatalogs({
    [CatalogType.STIMULATION_GROUP_TYPE]: { _sort: 'name', _filters: { is_active: true } },
    [CatalogType.HEALTH_CENTER]: { _sort: 'name' },
    [CatalogType.USER]: { _sort: 'name' },
  })

  const doSubmit = handleSubmit(async (values) => {
    const result = await crud.submit(values)
    if (result) {
      onSaved(result)
      onClose()
    }
  })

  return (
    <Modal
      isOpen
      onClose={onClose}
      title={mode === CrudMode.EDIT ? 'Editar grupo' : 'Nuevo grupo'}
      size="lg"
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose} disabled={isSaving} className="min-w-[80px]">
            Cancelar
          </Button>
          <Button type="button" onClick={() => void doSubmit()} loading={isSaving} className="min-w-[100px]">
            {isSaving ? 'Guardando…' : mode === CrudMode.EDIT ? 'Actualizar' : 'Crear'}
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
              <FieldLabel htmlFor="group-name" required>
                Nombre
              </FieldLabel>
              <Input id="group-name" disabled={isSaving} {...register('name')} />
              <FieldError>{errors.name?.message}</FieldError>
            </Field>
            <Field className="col-span-12 sm:col-span-6">
              <FieldLabel htmlFor="group-stimulation_group_type_id" required>
                Tipo de grupo
              </FieldLabel>
              <Controller
                name="stimulation_group_type_id"
                control={control}
                render={({ field }) => (
                  <SearchSelect<ICatalogItem>
                    id="group-stimulation_group_type_id"
                    value={field.value || null}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={catalogs[CatalogType.STIMULATION_GROUP_TYPE] ?? []}
                    isDisabled={isSaving}
                    isLoading={isLoadingCatalogs}
                    invalid={Boolean(errors.stimulation_group_type_id)}
                    placeholder="Seleccionar…"
                  />
                )}
              />
              <FieldError>{errors.stimulation_group_type_id?.message}</FieldError>
            </Field>
            <Field className="col-span-12 sm:col-span-6">
              <FieldLabel htmlFor="group-health_center_id" required>
                Centro de salud
              </FieldLabel>
              <Controller
                name="health_center_id"
                control={control}
                render={({ field }) => (
                  <SearchSelect<ICatalogItem>
                    id="group-health_center_id"
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
              <FieldLabel htmlFor="group-promoter_user_id" required>
                Persona promotora
              </FieldLabel>
              <Controller
                name="promoter_user_id"
                control={control}
                render={({ field }) => (
                  <SearchSelect<ICatalogItem>
                    id="group-promoter_user_id"
                    value={field.value || null}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={catalogs[CatalogType.USER] ?? []}
                    isDisabled={isSaving}
                    isLoading={isLoadingCatalogs}
                    invalid={Boolean(errors.promoter_user_id)}
                    placeholder="Seleccionar…"
                  />
                )}
              />
              <FieldError>{errors.promoter_user_id?.message}</FieldError>
            </Field>
            <Field className="col-span-12 sm:col-span-6">
              <FieldLabel htmlFor="group-start_date" required>
                Fecha de inicio
              </FieldLabel>
              <Input id="group-start_date" type="date" disabled={isSaving} {...register('start_date')} />
              <FieldError>{errors.start_date?.message}</FieldError>
            </Field>
            <Field className="col-span-12 sm:col-span-6">
              <FieldLabel htmlFor="group-end_date">Fecha de fin</FieldLabel>
              <Input id="group-end_date" type="date" placeholder="Opcional" disabled={isSaving} {...register('end_date')} />
              <FieldError>{errors.end_date?.message}</FieldError>
            </Field>
            <Field className="col-span-12">
              <FieldLabel htmlFor="group-is_active">Activo</FieldLabel>
              <Controller
                name="is_active"
                control={control}
                render={({ field }) => (
                  <Switch
                    id="group-is_active"
                    checked={field.value}
                    onCheckedChange={field.onChange}
                    disabled={isSaving}
                  />
                )}
              />
            </Field>
          </div>
        </FieldGroup>
      </form>
    </Modal>
  )
}
