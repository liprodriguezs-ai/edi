import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { rescoreFormSchema, type IScreening, type RescoreFormValues } from '@/modules/screening/schema'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { screeningErrorMessage } from '@/modules/screening/utils/screeningErrors'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { toast } from '@/shared/hooks/useToast'
// D11 — reuses the instrument module's own read-only version resource
// ("from the instrument module's version resource, published only") rather
// than duplicating an instrument-version fetch inside this module.
import type { IInstrumentVersion } from '@/modules/instrument/schema'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface RescoreModalProps {
  screening: IScreening
  onClose: () => void
  onDone: () => void
}

/**
 * REQ-SUI-008/D11 — reason required, `authoritative` default OFF (D11:
 * simulations may roam versions, mutations are pinned). The version picker
 * only matters when non-authoritative and is loaded lazily on mount (this
 * modal itself is only mounted on demand, so no eager cost).
 */
export function RescoreModal({ screening, onClose, onDone }: RescoreModalProps) {
  const [isSaving, setIsSaving] = useState(false)
  const [versions, setVersions] = useState<IInstrumentVersion[]>([])

  const {
    register,
    handleSubmit,
    control,
    watch,
    formState: { errors },
  } = useForm<RescoreFormValues>({
    resolver: zodResolver(rescoreFormSchema),
    defaultValues: { reason: '', authoritative: false, instrument_version_key: '' },
  })

  const authoritative = watch('authoritative')

  useEffect(() => {
    if (authoritative || versions.length > 0) return
    void ScreeningResource.rescoreVersions()
      .then(setVersions)
      .catch(() => setVersions([]))
  }, [authoritative, versions.length])

  const doSubmit = handleSubmit(async (values) => {
    setIsSaving(true)
    try {
      await ScreeningResource.rescore(screening.id, {
        reason: values.reason,
        authoritative: values.authoritative,
        instrument_version_key: values.authoritative ? undefined : values.instrument_version_key || undefined,
      })
      toast.success(values.authoritative ? 'Resultado actualizado' : 'Verificación lista, el resultado guardado no cambió')
      onDone()
    } catch (err) {
      toast.error(screeningErrorMessage(err, 'No se pudo verificar el cálculo'))
    } finally {
      setIsSaving(false)
    }
  })

  return (
    <ConfirmDialog
      isOpen
      title="Verificar cálculo"
      description="Herramienta de auditoría. No hace falta para aplicar la prueba: el resultado ya se calculó al calificar."
      confirmLabel="Verificar"
      isLoading={isSaving}
      loadingLabel="Procesando…"
      onConfirm={() => void doSubmit()}
      onCancel={onClose}
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
            <FieldLabel htmlFor="rescore-authoritative">Actualizar el resultado guardado</FieldLabel>
            <Controller
              name="authoritative"
              control={control}
              render={({ field }) => (
                <Switch
                  id="rescore-authoritative"
                  checked={field.value}
                  onCheckedChange={field.onChange}
                  disabled={isSaving}
                />
              )}
            />
            <FieldDescription>
              Apagado: solo muestra qué resultado daría, incluso con otra versión del instrumento; no cambia
              nada. Encendido: vuelve a calcular con las reglas de esta prueba y guarda el resultado.
            </FieldDescription>
          </Field>

          {!authoritative && (
            <Field>
              <FieldLabel htmlFor="rescore-version">Versión del instrumento (simulación)</FieldLabel>
              <Controller
                name="instrument_version_key"
                control={control}
                render={({ field }) => (
                  <Select value={field.value || undefined} onValueChange={field.onChange} disabled={isSaving}>
                    <SelectTrigger id="rescore-version" className="w-full">
                      <SelectValue placeholder="Versión propia de la Prueba EDI" />
                    </SelectTrigger>
                    <SelectContent>
                      {versions.map((version) => (
                        <SelectItem key={version.id} value={version.key}>
                          {version.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            </Field>
          )}

          <Field>
            <FieldLabel htmlFor="rescore-reason" required>
              Motivo
            </FieldLabel>
            <Textarea id="rescore-reason" rows={3} disabled={isSaving} {...register('reason')} />
            <FieldError>{errors.reason?.message}</FieldError>
          </Field>
        </FieldGroup>
      </form>
    </ConfirmDialog>
  )
}
