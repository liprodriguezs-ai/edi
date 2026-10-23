import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import {
  confirmatoryTestFormSchema,
  type ConfirmatoryTestFormValues,
  type IScreening,
} from '@/modules/screening/schema'
import { ConfirmatoryTestResource } from '@/modules/screening/services/ConfirmatoryTestResource'
import { screeningErrorMessage } from '@/modules/screening/utils/screeningErrors'
import { Modal } from '@/shared/components/Modal'
import { DescriptionItem, DescriptionList } from '@/shared/components/molecules/DescriptionList'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface ConfirmatoryTestSectionProps {
  screening: IScreening
  onSaved: () => void
}

/**
 * REQ-SUI-010 — visible only when `completed` AND global color is red. No
 * standalone page/route/menu entry: managed entirely from the detail.
 */
export function ConfirmatoryTestSection({ screening, onSaved }: ConfirmatoryTestSectionProps) {
  const [modalOpen, setModalOpen] = useState(false)

  const isCompleted = screening.screening_status?.key === 'completed'
  const isRed = screening.result_color?.key === 'red'

  if (!isCompleted || !isRed) return null

  const existing = screening.confirmatory_test

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-3">
        <CardTitle className="text-base">Prueba confirmatoria</CardTitle>
        {!existing && (
          <PermissionGuard permission="create.confirmatory_test">
            <Button size="sm" onClick={() => setModalOpen(true)}>
              Registrar
            </Button>
          </PermissionGuard>
        )}
      </CardHeader>
      <CardContent>
        {existing ? (
          <DescriptionList columns={2} variant="plain">
            <DescriptionItem label="Prueba" value={existing.test_name} />
            <DescriptionItem
              label="Fecha"
              value={existing.applied_at ? displayFormatDate(existing.applied_at, 'mediumDate') : '—'}
            />
            <DescriptionItem label="Resultado" value={existing.confirmatory_test_result?.name ?? 'Pendiente'} />
            {existing.notes && <DescriptionItem label="Notas" value={existing.notes} className="sm:col-span-2" />}
          </DescriptionList>
        ) : (
          <InlineEmpty>Sin prueba confirmatoria registrada.</InlineEmpty>
        )}
      </CardContent>

      {modalOpen && (
        <ConfirmatoryTestFormModal
          screeningId={screening.id}
          onClose={() => setModalOpen(false)}
          onSaved={() => {
            setModalOpen(false)
            onSaved()
          }}
        />
      )}
    </Card>
  )
}

function ConfirmatoryTestFormModal({
  screeningId,
  onClose,
  onSaved,
}: {
  screeningId: string
  onClose: () => void
  onSaved: () => void
}) {
  const [isSaving, setIsSaving] = useState(false)

  const {
    register,
    handleSubmit,
    control,
    formState: { errors },
  } = useForm<ConfirmatoryTestFormValues>({
    resolver: zodResolver(confirmatoryTestFormSchema),
    defaultValues: {
      screening_id: screeningId,
      test_name: '',
      applied_at: '',
      confirmatory_test_result_id: '',
      notes: '',
    },
  })

  // Catalog loads on modal open only (never eager, REQ-SUI-010).
  const { catalogs, isLoading: isLoadingCatalog } = useCatalogs({
    [CatalogType.CONFIRMATORY_TEST_RESULT]: { _sort: 'name' },
  })

  const doSubmit = handleSubmit(async (values) => {
    setIsSaving(true)
    try {
      await ConfirmatoryTestResource.create({
        ...values,
        applied_at: values.applied_at || undefined,
        confirmatory_test_result_id: values.confirmatory_test_result_id || undefined,
      })
      toast.success('Prueba confirmatoria registrada')
      onSaved()
    } catch (err) {
      toast.error(screeningErrorMessage(err, 'No se pudo registrar la prueba confirmatoria'))
    } finally {
      setIsSaving(false)
    }
  })

  return (
    <Modal
      isOpen
      onClose={onClose}
      title="Registrar prueba confirmatoria"
      footer={
        <>
          <Button type="button" variant="outline" onClick={onClose} disabled={isSaving}>
            Cancelar
          </Button>
          <Button type="submit" form="confirmatory-test-form" loading={isSaving}>
            Registrar
          </Button>
        </>
      }
    >
      <form
        id="confirmatory-test-form"
        onSubmit={(e) => {
          e.preventDefault()
          void doSubmit(e)
        }}
      >
        <RequiredFieldsLegend />
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor="confirmatory-test_name" required>
              Nombre de la prueba
            </FieldLabel>
            <Input
              id="confirmatory-test_name"
              placeholder="Ej. Battelle"
              disabled={isSaving}
              {...register('test_name')}
            />
            <FieldError>{errors.test_name?.message}</FieldError>
          </Field>

          <Field>
            <FieldLabel htmlFor="confirmatory-applied_at">Fecha de aplicación</FieldLabel>
            <Input id="confirmatory-applied_at" type="date" disabled={isSaving} {...register('applied_at')} />
            <FieldError>{errors.applied_at?.message}</FieldError>
          </Field>

          <Field>
            <FieldLabel htmlFor="confirmatory-result">Resultado</FieldLabel>
            <Controller
              name="confirmatory_test_result_id"
              control={control}
              render={({ field }) => (
                <Select value={field.value || undefined} onValueChange={field.onChange} disabled={isSaving}>
                  <SelectTrigger id="confirmatory-result" className="w-full">
                    <SelectValue placeholder={isLoadingCatalog ? 'Cargando…' : 'Seleccionar…'} />
                  </SelectTrigger>
                  <SelectContent>
                    {catalogs[CatalogType.CONFIRMATORY_TEST_RESULT]?.map((item) => (
                      <SelectItem key={item.id} value={item.id}>
                        {item.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
          </Field>

          <Field>
            <FieldLabel htmlFor="confirmatory-notes">Notas</FieldLabel>
            <Textarea id="confirmatory-notes" rows={3} disabled={isSaving} {...register('notes')} />
          </Field>
        </FieldGroup>
      </form>
    </Modal>
  )
}
