import { useLayoutContext } from '@/app/layout/useLayoutContext'
import { Button } from '@/components/ui/button'
import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { periodicityCadenceLabel } from '@/modules/chargeConcept/helpers/chargeGeneration'
import { ChargeConceptResource } from '@/modules/chargeConcept/services/ChargeConceptResource'
import { ChargeBatchResource } from '@/modules/chargeBatch/services/ChargeBatchResource'
import { resolveAmountFlags } from '@/modules/chargePolicy/components/AmountModeSelector'
import { CockpitBatchList } from '@/modules/chargePolicy/components/CockpitBatchList'
import { PolicyConfigForm } from '@/modules/chargePolicy/components/PolicyConfigForm'
import { PolicyPreviewPanel } from '@/modules/chargePolicy/components/PolicyPreviewPanel'
import { SurchargeRulesSection } from '@/modules/chargePolicy/components/SurchargeRulesSection'
import { SingleTargetSelector } from '@/shared/components/target/SingleTargetSelector'
import { buildPolicyDefaults } from '@/modules/chargePolicy/buildPolicyDefaults'
import {
  buildPolicyCreatePayload,
  buildPolicyUpdatePayload,
} from '@/modules/chargePolicy/buildPolicyPayload'
import {
  buildPolicyTargetSelector,
  chargePolicyFormSchema,
  PERIODICITY,
  type ChargePolicyFormValues,
  type IChargePolicy,
} from '@/modules/chargePolicy/schema'
import { ChargePolicyResource } from '@/modules/chargePolicy/services/ChargePolicyResource'
import { useChargePolicyStore } from '@/modules/chargePolicy/store/chargePolicyStore'
import { ConfigurationResource } from '@/modules/configuration/services/ConfigurationResource'
import { RegisterPaymentSheet } from '@/modules/payment/components/RegisterPaymentSheet'
import { ApiError } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { Callout } from '@/shared/components/atoms/Callout'
import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { StatusPill } from '@/shared/components/atoms/StatusPill'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { useEntityDetail } from '@/shared/hooks/useEntityDetail'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import {
  GRACE_DAYS_SLUG,
  computeConcreteLifecycleMilestones,
  computeLifecycleMilestones,
  resolveTimingSettings,
} from '@/shared/utils/chargeLifecycle'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, CalendarClock } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useForm, useWatch, type FieldPath } from 'react-hook-form'
import { Link, useNavigate, useParams } from 'react-router-dom'

const SECTION = 'rounded-[14px] border border-border bg-card p-5 shadow-sm'
const SECTION_TITLE =
  'mb-4 text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'

/**
 * Resumen de vigencia para el encabezado. Eventual = cobro directo; recurrente con
 * `from` = ventana "Vig desde–hasta". Helper puro: evita el ternario anidado inline.
 */
function headerValidity(isEventual: boolean, from?: string | null, to?: string | null): string {
  if (isEventual) return 'Cobro directo'
  if (!from) return ''
  const base = `Vig ${displayFormatDate(from, 'mediumDate')}`
  return to ? `${base}–${displayFormatDate(to, 'mediumDate')}` : base
}

/**
 * Form UNIFICADO de regla de cobro (charge_policy), en formato COCKPIT de 2 columnas:
 * a la IZQUIERDA se configura (concepto, identidad, periodicidad, monto, gracia,
 * alcance, recargos) y a la DERECHA se ve la CONSECUENCIA (ciclo de vida del cargo +
 * tiempos del clúster). En mobile colapsa a una sola columna.
 *
 * El backend crea la policy + su lote inicial (is_initial = true) en una sola operación;
 * el cron clona ese lote por periodo. En edición, debajo del cockpit se muestra una
 * sección de SOLO LECTURA con los cobros generados (trazabilidad).
 */
export const ChargeRuleFormPage = () => {
  const navigate = useNavigate()
  const { conceptId = '', id = '' } = useParams<{ conceptId: string; id: string }>()
  const isEdit = Boolean(id)
  // El concepto se elige EN el form cuando es alta sin conceptId en la URL (ruta /charge-policy/new).
  const needsConceptPicker = !isEdit && !conceptId

  const {
    entity: policy,
    isLoading: isLoadingPolicy,
    isError: isPolicyError,
    isSuccess: isPolicySuccess,
    notFound: policyNotFound,
    refetch: refetchPolicy,
  } = useEntityDetail<IChargePolicy>({
    queryKey: ['charge-policy', id],
    queryFn: () =>
      ChargePolicyResource.show(id, {
        include:
          'concept,amount_mode,periodicity,charge_batches,surcharge_rules,surcharge_rules.surcharge_mode',
      }),
    enabled: isEdit,
  })

  // Gate del front: espeja `withExists('charges as has_billed')` del backend. Bloquea
  // alcance (target_type/target_selector) y periodicidad; vigencia y monto siguen editables.
  const hasBilled = Boolean(policy?.has_billed)

  const resolvedConceptId = policy?.charge_concept_id ?? conceptId

  const { data: concept } = useQuery({
    queryKey: ['charge-concept', resolvedConceptId],
    queryFn: () => ChargeConceptResource.show(resolvedConceptId),
    enabled: Boolean(resolvedConceptId),
  })

  const defaultValues = useMemo(
    () => buildPolicyDefaults(resolvedConceptId, policy ?? null, new Date()),
    [resolvedConceptId, policy]
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    watch,
    setValue,
    setError,
    formState: { errors },
  } = useForm<ChargePolicyFormValues>({
    resolver: zodResolver(chargePolicyFormSchema),
    defaultValues,
  })

  // Mapea un 422 del backend a errores inline de RHF en los campos conocidos del form Y
  // muestra el mensaje del backend como toast. El resaltado inline puede quedar fuera de
  // pantalla (ej. periodicidad arriba al submitear desde el botón de abajo), así que el
  // toast garantiza feedback visible. Cubre reglas de negocio cross-field como
  // "Ya existe una política activa para este concepto y periodicidad".
  const handleValidationError = useCallback(
    (err: ApiError): boolean => {
      const fieldErrors = err.payload?.errors
      if (!fieldErrors) return false
      // Solo los campos que el backend PUEDE rechazar por key. El alcance se valida
      // server-side como `target_type` / `target_selector` (NUNCA los campos internos
      // del selector como `target_tower_ids`, que solo existen en el form), así que esos
      // no se mapean. `target_selector` no es un campo del form: si el backend lo rechaza,
      // se ancla en `target_type` (el control visible del alcance) para resaltado inline.
      const knownFields: FieldPath<ChargePolicyFormValues>[] = [
        'charge_concept_id',
        'name',
        'periodicity_id',
        'valid_from',
        'valid_to',
        'target_type',
        'issued_at',
        'due_date',
        'due_soon_days',
      ]
      for (const field of knownFields) {
        const message = fieldErrors[field]?.[0]
        if (message) {
          setError(field, { type: 'server', message })
        }
      }

      // `target_selector` lo emite el backend como key de error propia; el form no tiene
      // un control con ese nombre, así que su mensaje se ancla en `target_type`.
      const selectorMessage = fieldErrors.target_selector?.[0]
      if (selectorMessage) {
        setError('target_type', { type: 'server', message: selectorMessage })
      }

      // Errores anidados del calendario de recargos: `surcharge_rules.<i>.<campo>`.
      // El backend devuelve la clave punteada; la mapeamos al path de array de RHF.
      const surchargePattern =
        /^surcharge_rules\.(\d+)\.(from_month|surcharge_mode_id|surcharge_value|recurrence|cap_amount)$/
      for (const [key, messages] of Object.entries(fieldErrors)) {
        const match = surchargePattern.exec(key)
        const message = messages?.[0]
        if (match && message) {
          const path =
            `surcharge_rules.${match[1]}.${match[2]}` as FieldPath<ChargePolicyFormValues>
          setError(path, { type: 'server', message })
        }
      }

      // Toast con el mensaje del backend (siempre visible) + resaltado inline ya aplicado.
      // Devolvemos true para suprimir el toast genérico de useBaseCrud (evita duplicado).
      toast.error(err.payload?.message ?? 'Revisa los campos marcados.')
      return true
    },
    [setError]
  )

  const crud = useBaseCrud<IChargePolicy, Record<string, unknown>>({
    store: useChargePolicyStore,
    resource: {
      save: ChargePolicyResource.create,
      update: ChargePolicyResource.update,
    },
    mode: isEdit ? CrudMode.EDIT : CrudMode.CREATE,
    entityId: id || undefined,
    initialData: policy ?? null,
    onError: handleValidationError,
  })
  const isSaving = crud.store.isSaving

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  // Form de configuración densa: aprovecha todo el ancho del área de contenido (el
  // layout quita su `max-w-7xl` mientras esta página está montada). Se restablece al salir.
  const { setFullWidth } = useLayoutContext()
  useEffect(() => {
    setFullWidth(true)
    return () => setFullWidth(false)
  }, [setFullWidth])

  const { catalogs } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.UNIT_TYPE]: { _sort: 'order' },
        [CatalogType.TOWER]: { _sort: 'order' },
        [CatalogType.SECTION]: { _sort: 'order' },
        [CatalogType.UNIT_FEATURE]: { _sort: 'name' },
        [CatalogType.SURCHARGE_MODE]: { _sort: 'order' },
      }),
      []
    )
  )

  const { data: collectionSettings } = useQuery({
    queryKey: ['config', 'collection-settings'],
    queryFn: () => ConfigurationResource.getCollectionSettings(),
    staleTime: 5 * 60 * 1000,
    enabled: !isEdit || isPolicySuccess,
  })

  const amountModeId = watch('amount_mode_id')
  const isActive = watch('is_active')
  const periodicityId = watch('periodicity_id')
  const validFrom = watch('valid_from')
  const validTo = watch('valid_to')
  const targetType = watch('target_type')
  const issuedAt = watch('issued_at')
  const dueDate = watch('due_date')
  const graceDays = watch('grace_days')
  const dueSoonOverride = watch('due_soon_days')

  const [singleUnitLabel, setSingleUnitLabel] = useState('')
  const [payUnit, setPayUnit] = useState<{ id: string; label: string } | null>(null)

  const amountFlags = useMemo(() => resolveAmountFlags(amountModeId), [amountModeId])
  // El modo de cobro lo determina la periodicidad: EVENTUAL = cobro único (directo);
  // cualquier otra periodicidad = recurrente (periódico). No hay campo "modo de cobro".
  const isEventual = periodicityId === PERIODICITY.EVENTUAL
  const timing = useMemo(() => resolveTimingSettings(collectionSettings), [collectionSettings])

  // Días "por vencer" efectivos: el override de la regla gana sobre el valor del clúster.
  // El override puede ser 0 (válido), por eso se compara contra null, no con ??-truthiness.
  const dueSoonOverridden = dueSoonOverride != null
  const effectiveDueSoonDays = dueSoonOverridden ? dueSoonOverride : timing.dueSoonDays

  // Días de gracia efectivos: el override de la regla gana sobre el valor del clúster.
  // El override puede ser 0 (válido), por eso se compara contra null, no con ??-truthiness.
  const graceOverridden = graceDays != null
  const effectiveGraceDays = graceOverridden ? graceDays : timing.graceDays

  // Placeholder del campo "Días de gracia": muestra el valor REAL configurado en el
  // clúster; si el clúster no lo tiene, queda vacío (NUNCA un número inventado). No se
  // usa timing.graceDays porque ese ya trae el default 5 — el placeholder debe reflejar
  // solo lo que el clúster configuró.
  const graceClusterValue = collectionSettings?.[GRACE_DAYS_SLUG]
  const graceDaysPlaceholder =
    graceClusterValue == null || graceClusterValue === '' ? undefined : Number(graceClusterValue)

  // Preview del alcance (solo en ALTA: la vista previa no se renderiza en edición).
  // Se suscribe SOLO a los campos de alcance vía useWatch (no a todo el form con
  // watch()), evitando un re-render del cockpit en cada tecla de cualquier campo.
  const targetFields = useWatch({
    control,
    name: [
      'target_type',
      'target_unit_type_ids',
      'target_tower_ids',
      'target_section_ids',
      'target_floor',
      'target_unit_ids',
      'target_feature_ids',
      'target_unit_id',
    ],
  })
  const previewSelector = useMemo(() => {
    if (isEdit) return null
    const [
      target_type,
      target_unit_type_ids,
      target_tower_ids,
      target_section_ids,
      target_floor,
      target_unit_ids,
      target_feature_ids,
      target_unit_id,
    ] = targetFields
    return buildPolicyTargetSelector({
      target_type,
      target_unit_type_ids,
      target_tower_ids,
      target_section_ids,
      target_floor,
      target_unit_ids,
      target_feature_ids,
      target_unit_id,
    })
  }, [isEdit, targetFields])

  // Vista previa del ciclo de vida cuando hay ambas fechas. Eventual siempre las pide
  // (incluso en edición, donde se hidratan del lote inicial); recurrente no las captura
  // → quedan vacías y este preview no se muestra (el panel usa la gráfica paramétrica).
  const lifecycleMilestones = useMemo(() => {
    if (!issuedAt || !dueDate) return null
    return computeConcreteLifecycleMilestones({
      issuedAt,
      dueDate,
      dueSoonDays: effectiveDueSoonDays,
      graceDays: effectiveGraceDays,
    })
  }, [issuedAt, dueDate, effectiveDueSoonDays, effectiveGraceDays])

  // Ciclo de vida del cobro RECURRENTE: gráfica paramétrica derivada de la periodicidad +
  // gracia + vigencia (no necesita fechas explícitas; el sistema las deriva por período).
  const parametricMilestones = useMemo(() => {
    if (isEventual || !periodicityId) return null
    return computeLifecycleMilestones({
      periodicityId,
      generationDay: timing.generationDay,
      dueSoonDays: effectiveDueSoonDays,
      graceDays: effectiveGraceDays,
      anchorDate: validFrom || undefined,
    })
  }, [
    isEventual,
    periodicityId,
    timing.generationDay,
    effectiveDueSoonDays,
    effectiveGraceDays,
    validFrom,
  ])

  const doSubmit = handleSubmit(async (values) => {
    const payload = isEdit
      ? buildPolicyUpdatePayload(values, hasBilled)
      : buildPolicyCreatePayload(values)
    let result: IChargePolicy | null = null
    try {
      result = await crud.submit(payload)
    } catch {
      // El error ya lo maneja useBaseCrud (toast + resaltado inline). Lo atajamos acá para
      // que NO se propague a React Hook Form y se loguee como ApiError en la consola.
      return
    }
    if (!result) return
    // Tras crear, abrimos el form en modo edición (id) para ver los cobros generados.
    if (!isEdit && result.id) {
      navigate(`/charge-policy/${result.id}`)
    }
  })

  const conceptName = concept?.name ?? policy?.concept?.name ?? 'Programación de cobro'
  // Sin conceptId (alta global), volvemos al listado global de políticas.
  const backUrl = resolvedConceptId
    ? `/charge-concept/${resolvedConceptId}/policies`
    : '/charge-policy'
  const backLabel = resolvedConceptId ? 'Programaciones del concepto' : 'Programación de cobros'

  // Resumen de periodicidad/vigencia para el encabezado.
  const cadence = periodicityCadenceLabel(periodicityId)
  const vigencia = headerValidity(isEventual, validFrom, validTo)

  if (isEdit && isLoadingPolicy) {
    return <p className='py-16 text-center text-sm text-[var(--ink-3)]'>Cargando programación…</p>
  }

  if (isEdit && isPolicyError) {
    return (
      <EntityNotFound
        variant={policyNotFound ? 'notFound' : 'error'}
        title={policyNotFound ? 'Programación de cobro no encontrada' : undefined}
        backTo={backUrl}
        backLabel={backLabel}
        onRetry={policyNotFound ? undefined : refetchPolicy}
      />
    )
  }

  const saveButton = (
    <Button
      type='submit'
      form='charge-rule-form'
      disabled={isSaving}
      className='min-w-[140px] rounded-full'
    >
      {isSaving ? 'Guardando…' : isEdit ? 'Guardar' : 'Crear programación'}
    </Button>
  )

  return (
    <div className='space-y-5 pb-2'>
      {/* Encabezado de identidad: volver + título + resumen de estado. SIN sticky —
          el acceso a "Guardar" al scrollear lo da la barra de acción inferior. */}
      <div className={SECTION}>
        <Link
          to={backUrl}
          className='mb-1.5 inline-flex items-center gap-1 text-[12.5px] text-[var(--ink-3)] hover:text-foreground'
        >
          <ArrowLeft className='size-3.5' />
          {backLabel}
        </Link>
        <div className='flex flex-wrap items-start justify-between gap-4'>
          <div className='min-w-0'>
            <h1 className='text-[26px] font-semibold leading-[1.12] tracking-[-0.02em]'>
              {conceptName} <span className='text-[var(--ink-3)]'>· Programación de cobro</span>
            </h1>
            <div className='mt-2 flex flex-wrap items-center gap-2.5 text-[13px] text-[var(--ink-3)]'>
              <StatusPill tone={isActive ? 'g' : 'r'}>
                {isActive ? 'Activa' : 'Inactiva'}
              </StatusPill>
              <span className='inline-flex items-center gap-1.5'>
                <CalendarClock className='size-3.5' aria-hidden />
                {isEventual ? 'Directo' : `Periódico · ${cadence}`}
              </span>
              {vigencia && <span>{vigencia}</span>}
            </div>
          </div>
        </div>
      </div>

      {/* Cockpit: configuración (izq) + vista previa (der). Mobile = 1 columna. */}
      <div className='grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_360px]'>
        {/* IZQUIERDA — configuración de la regla + lote inicial + recargos. */}
        <form
          id='charge-rule-form'
          onSubmit={(e) => {
            e.preventDefault()
            void doSubmit(e)
          }}
          className='space-y-5'
        >
          <RequiredFieldsLegend />
          <PolicyConfigForm
            control={control}
            register={register}
            errors={errors}
            amountFlags={amountFlags}
            periodicityId={periodicityId}
            needsConceptPicker={needsConceptPicker}
            dueSoonDaysPlaceholder={timing.dueSoonDays}
            graceDaysPlaceholder={graceDaysPlaceholder}
            isSaving={isSaving}
            hasBilled={hasBilled}
          />

          {/* Alcance del lote inicial — aplica a AMBOS modos. */}
          <section className={SECTION}>
            <h3 className={SECTION_TITLE}>Alcance del cobro</h3>
            {hasBilled && (
              <Callout icon={CalendarClock} className='mb-4'>
                Esta programación ya generó cobros: el alcance y la periodicidad quedan bloqueados.
                La vigencia y el monto siguen editables.
              </Callout>
            )}
            <div className='space-y-6'>
              <SingleTargetSelector
                control={control}
                register={register}
                errors={errors}
                targetType={targetType}
                targetUnitId={watch('target_unit_id')}
                targetUnitIds={watch('target_unit_ids') ?? []}
                catalogs={{
                  unitTypes: catalogs[CatalogType.UNIT_TYPE] ?? [],
                  towers: catalogs[CatalogType.TOWER] ?? [],
                  sections: catalogs[CatalogType.SECTION] ?? [],
                  features: catalogs[CatalogType.UNIT_FEATURE] ?? [],
                }}
                disabled={isSaving || hasBilled}
                idPrefix='policy'
                singleUnitLabel={singleUnitLabel}
                onSingleUnitLabelChange={setSingleUnitLabel}
                onUnitSelect={(uid) => setValue('target_unit_id', uid, { shouldValidate: true })}
                onUnitIdsChange={(ids) =>
                  setValue('target_unit_ids', ids, { shouldValidate: true })
                }
                previewScope='charge-batch'
                previewSelector={previewSelector}
                previewFetcher={!isEdit ? ChargeBatchResource.preview : undefined}
                previewNoun='unidades incluidas'
              />
            </div>
          </section>

          {/* Fechas del cobro (solo eventual / cobro único). La gráfica del ciclo vive en
              la columna de vista previa (derecha): la fecha es config, el ciclo es consecuencia. */}
          {isEventual && (
            <section className={SECTION}>
              <h3 className={SECTION_TITLE}>Fechas del cobro</h3>
              <div className='grid grid-cols-12 gap-4'>
                <Field className='col-span-6'>
                  <FieldLabel htmlFor='rule-issued' required>
                    Fecha de generación
                  </FieldLabel>
                  <Input
                    id='rule-issued'
                    type='date'
                    {...register('issued_at')}
                    disabled={isSaving || hasBilled}
                  />
                  <FieldError>{errors.issued_at?.message}</FieldError>
                </Field>
                <Field className='col-span-6'>
                  <FieldLabel htmlFor='rule-due' required>
                    Vencimiento
                  </FieldLabel>
                  <Input
                    id='rule-due'
                    type='date'
                    {...register('due_date')}
                    disabled={isSaving || hasBilled}
                  />
                  <FieldError>{errors.due_date?.message}</FieldError>
                </Field>
              </div>
            </section>
          )}

          {/* Recargo moratorio (recargos escalonados por mes de mora). */}
          <SurchargeRulesSection
            control={control}
            register={register}
            errors={errors}
            surchargeModes={catalogs[CatalogType.SURCHARGE_MODE] ?? []}
            isSaving={isSaving}
          />
        </form>

        {/* DERECHA — vista previa (consecuencia), sticky en desktop. */}
        <aside className='space-y-5 lg:sticky lg:top-6 lg:self-start'>
          <PolicyPreviewPanel
            isEventual={isEventual}
            concreteMilestones={lifecycleMilestones}
            parametricMilestones={parametricMilestones}
            generationDay={timing.generationDay}
            effectiveDueSoonDays={effectiveDueSoonDays}
            dueSoonOverridden={dueSoonOverridden}
            effectiveGraceDays={effectiveGraceDays}
            graceOverridden={graceOverridden}
          />
        </aside>
      </div>

      {/* Trazabilidad (solo lectura) — cobros generados por la regla, fuera del form. */}
      {isEdit && id && (
        <section>
          <h3 className={SECTION_TITLE}>Cobros generados</h3>
          <CockpitBatchList
            policyId={id}
            periodicityId={policy?.periodicity_id}
            onCollect={(unit) => setPayUnit(unit)}
          />
        </section>
      )}

      {/* Barra de acción persistente: "Guardar" siempre a mano en este form largo. Fondo
          SÓLIDO + sombra ascendente; vive al final del flujo y se ancla al borde inferior
          del viewport — un toolbar, no una card flotante que tape contenido por sorpresa. */}
      <div className='sticky bottom-0 z-10 flex flex-wrap items-center justify-between gap-3 rounded-[14px] border border-border bg-card px-4 py-3 shadow-[0_-10px_28px_-18px_rgba(20,40,25,0.4)]'>
        <p className='min-w-0 flex-1 truncate text-[12.5px] text-[var(--ink-3)]'>
          <span className='font-medium text-foreground'>{conceptName}</span>
          {' · '}
          {isEventual ? 'Cobro directo' : `Periódico · ${cadence}`}
          {vigencia ? ` · ${vigencia}` : ''}
        </p>
        {saveButton}
      </div>

      <RegisterPaymentSheet
        isOpen={Boolean(payUnit)}
        onClose={() => setPayUnit(null)}
        presetUnitId={payUnit?.id}
        presetUnitLabel={payUnit?.label}
      />
    </div>
  )
}
