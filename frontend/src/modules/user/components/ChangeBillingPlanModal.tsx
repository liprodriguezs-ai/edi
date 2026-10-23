import { Button } from '@/components/ui/button'
import { formatPrice } from '@/modules/checkoutSubscription/components/formatPrice'
import {
  billablePlans,
  contactMailtoHref,
  contactWhatsappHref,
  findContactSalesPlan,
  planForUnitCount,
} from '@/modules/checkoutSubscription/planSelection'
import type {
  BillingInterval,
  IBillingPlan,
  IPlanCatalog,
} from '@/modules/checkoutSubscription/schema'
import { BillingResource } from '@/modules/checkoutSubscription/services/BillingResource'
import { CheckoutSubscriptionResource } from '@/modules/checkoutSubscription/services/CheckoutSubscriptionResource'
import { resolveBillingError } from '@/modules/tenant/billing/billingErrors'
import type { IPreferentialSwapPreview } from '@/modules/tenant/billing/schema'
import { PlanPicker } from '@/modules/user/components/PlanPicker'
import { useSubscriptionChange } from '@/modules/user/hooks/useSubscriptionChange'
import { Modal } from '@/shared/components/Modal'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { unixSecondsToInstant } from '@/shared/utils/tenantTime'
import { AlertCircle, Mail, MessageCircle } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

interface ChangeBillingPlanModalProps {
  isOpen: boolean
  /** Clave del plan actual de la organización: se excluye del selector de destino. */
  currentPlanKey: string
  /**
   * Intervalo contratado. Los precios se muestran EN ESTE intervalo: cambiar de
   * plan no cambia el ciclo de cobro (eso es "Cambiar intervalo"), así que cotizar
   * en mensual a alguien que paga anual sería mostrarle un número que no va a pagar.
   */
  currentInterval: BillingInterval
  /** Unidades activas de la organización: es lo que decide qué banda le toca. */
  unitCount: number
  onClose: () => void
  /** Refresca la pantalla tras aplicar el cambio con éxito. */
  onApplied: () => void | Promise<void>
}

/**
 * Cambiar el plan de suscripción (basico↔premium) en modo self-serve del owner.
 * El catálogo de planes se carga LAZY (montaje condicional en el manager). Flujo:
 * elegir plan destino → vista previa del prorrateo → confirmar el cambio.
 *
 * Sólo se listan planes CON precio de lista: el plan de venta consultiva ("A medida")
 * se ofrece como CTA de contacto, porque su preview siempre responde 422.
 *
 * El preview reutiliza el mismo contrato que el swap preferencial de super-admin:
 * devuelve un desglose de líneas de Stripe (tarjeta) o un aviso (pago manual).
 */
export function ChangeBillingPlanModal({
  isOpen,
  currentPlanKey,
  currentInterval,
  unitCount,
  onClose,
  onApplied,
}: ChangeBillingPlanModalProps) {
  const [catalog, setCatalog] = useState<IPlanCatalog | null>(null)
  const [isLoadingCatalog, setIsLoadingCatalog] = useState(true)
  const [catalogError, setCatalogError] = useState<string | null>(null)

  const [selectedPlanKey, setSelectedPlanKey] = useState<string>('')
  const [preview, setPreview] = useState<IPreferentialSwapPreview | null>(null)
  const [isLoadingPreview, setIsLoadingPreview] = useState(false)
  const [previewError, setPreviewError] = useState<string | null>(null)

  const { apply, isApplying } = useSubscriptionChange({ onApplied })

  // Carga el catálogo una vez al montar. El modal se monta de forma condicional
  // en el manager, así que este fetch ocurre LAZY (sólo al abrir el modal).
  useEffect(() => {
    let active = true
    BillingResource.getCatalog()
      .then((data) => {
        if (active) setCatalog(data)
      })
      .catch(() => {
        if (active) setCatalogError('No se pudo cargar el catálogo de planes.')
      })
      .finally(() => {
        if (active) setIsLoadingCatalog(false)
      })
    return () => {
      active = false
    }
  }, [])

  const loadPreview = useCallback(async (planKey: string) => {
    setIsLoadingPreview(true)
    setPreviewError(null)
    setPreview(null)
    try {
      const data = await CheckoutSubscriptionResource.previewBillingPlanChange(planKey)
      setPreview(data)
    } catch (err) {
      setPreviewError(
        resolveBillingError(err, 'No se pudo calcular el prorrateo. Inténtalo de nuevo.')
      )
    } finally {
      setIsLoadingPreview(false)
    }
  }, [])

  const handleSelect = useCallback(
    (key: string) => {
      setSelectedPlanKey(key)
      void loadPreview(key)
    },
    [loadPreview]
  )

  const handleConfirm = useCallback(async () => {
    if (!selectedPlanKey || !preview) return
    // Para pago manual no se envía proration_date; para tarjeta se reenvía exacto.
    const prorationDate = preview.manual === true ? null : (preview.proration_date ?? null)
    const applied = await apply(() =>
      CheckoutSubscriptionResource.changeBillingPlan(selectedPlanKey, prorationDate)
    )
    if (applied) onClose()
  }, [apply, onClose, preview, selectedPlanKey])

  // Planes elegibles: los que tienen precio de lista, excepto el actual. El plan de
  // venta consultiva ("A medida") NO se lista: el backend rechaza su preview con 422
  // y el "Reintentar" nunca podría funcionar. En su lugar se ofrece contacto.
  const allBillablePlans = billablePlans(catalog?.plans ?? [])
  const availablePlans: IBillingPlan[] = allBillablePlans.filter((p) => p.key !== currentPlanKey)
  const currentPlan = catalog?.plans.find((p) => p.key === currentPlanKey) ?? null
  // El plan que le toca por su número de unidades. Es una AYUDA de lectura para no
  // obligar al dueño a comparar su número contra seis bandas a mano; la autoridad
  // sobre si el cambio procede sigue siendo `unit_limit_warning` del preview.
  const fittingPlan = planForUnitCount(allBillablePlans, unitCount)
  const contactSalesPlan = findContactSalesPlan(catalog?.plans ?? [])
  const contactMailto = contactMailtoHref(catalog)
  const contactWhatsapp = contactWhatsappHref(catalog)
  const currency = catalog?.currency ?? 'MXN'
  const isManual = preview?.manual === true
  // plan-downgrade-guard: presente cuando las unidades activas de la organización no caben
  // en el plan destino. El apply-422 ya cubre el caso (el hook muestra el mensaje del
  // backend), este panel sólo lo anticipa antes de confirmar y bloquea el botón.
  const unitLimitWarning = preview?.unit_limit_warning

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title='Cambiar plan'
      description='Elige el plan al que quieres migrar. Verás el ajuste de cobro antes de confirmar.'
      size='lg'
      footer={
        <>
          <Button
            variant='outline'
            onClick={onClose}
            disabled={isApplying}
            className='min-w-[80px]'
          >
            Cancelar
          </Button>
          <Button
            onClick={() => void handleConfirm()}
            loading={isApplying}
            disabled={!selectedPlanKey || !preview || isLoadingPreview || Boolean(unitLimitWarning)}
            className='min-w-[130px]'
          >
            {isApplying ? 'Cambiando...' : 'Confirmar cambio'}
          </Button>
        </>
      }
    >
      <div className='space-y-5 py-2'>
        {/* ── Selector de plan destino ── */}
        {isLoadingCatalog ? (
          <InlineLoading name='change-plan-catalog' />
        ) : catalogError ? (
          <p className='border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive'>
            {catalogError}
          </p>
        ) : availablePlans.length === 0 ? (
          <InlineEmpty>No hay otros planes disponibles en este momento.</InlineEmpty>
        ) : (
          <PlanPicker
            currentPlan={currentPlan}
            availablePlans={availablePlans}
            allBillablePlans={allBillablePlans}
            fittingPlan={fittingPlan}
            selectedPlanKey={selectedPlanKey}
            interval={currentInterval}
            currency={currency}
            unitCount={unitCount}
            onSelect={handleSelect}
          />
        )}

        {/* Venta consultiva: el plan "A medida" no se puede elegir aquí (no tiene
            precio de lista), así que se ofrece el mismo camino de contacto que
            /select-plan en vez de un combo que siempre falla. */}
        {!isLoadingCatalog && !catalogError && contactSalesPlan && (
          <div className='border border-border bg-muted/30 p-4'>
            <p className='text-sm font-medium text-foreground'>
              ¿Tu organización creció más de lo que cubren estos planes?
            </p>
            <p className='mt-1 text-sm text-muted-foreground'>
              Armamos un plan {contactSalesPlan.name.toLowerCase()} con un precio hecho para ti.
              Escríbenos y te respondemos con una propuesta.
            </p>
            <div className='mt-3 flex flex-wrap gap-2'>
              {contactMailto && (
                <Button asChild size='sm' variant='outline'>
                  <a href={contactMailto}>
                    <Mail className='mr-2 h-4 w-4' aria-hidden />
                    Escríbenos por correo
                  </a>
                </Button>
              )}
              {contactWhatsapp && (
                <Button asChild size='sm' variant='outline'>
                  <a href={contactWhatsapp} target='_blank' rel='noreferrer'>
                    <MessageCircle className='mr-2 h-4 w-4' aria-hidden />
                    Escríbenos por WhatsApp
                  </a>
                </Button>
              )}
              {!contactMailto && !contactWhatsapp && (
                <p className='text-sm text-muted-foreground'>
                  Contacta a nuestro equipo de ventas para recibir tu propuesta.
                </p>
              )}
            </div>
          </div>
        )}

        {/* ── Panel de vista previa (sólo visible al seleccionar un plan) ── */}
        {selectedPlanKey && (
          <>
            {isLoadingPreview && (
              <InlineLoading name='change-plan-preview' />
            )}

            {!isLoadingPreview && previewError && (
              <div className='space-y-3 border border-destructive/30 bg-destructive/5 p-4'>
                <div className='flex items-start gap-3 text-destructive'>
                  <AlertCircle className='mt-0.5 h-5 w-5 shrink-0' aria-hidden />
                  <p className='text-sm font-medium'>{previewError}</p>
                </div>
                <Button
                  size='sm'
                  variant='outline'
                  onClick={() => void loadPreview(selectedPlanKey)}
                >
                  Reintentar
                </Button>
              </div>
            )}

            {/* Preview pago manual */}
            {!isLoadingPreview && !previewError && preview && isManual && (
              <div className='space-y-2 border border-border bg-muted/30 p-4 text-sm'>
                <p className='text-foreground'>
                  {preview.message ?? 'El nuevo precio aplica en el próximo ciclo.'}
                </p>
                {typeof preview.new_price === 'number' && (
                  <p className='text-muted-foreground'>
                    Nuevo precio:{' '}
                    <span className='font-medium text-foreground'>
                      {formatPrice(preview.new_price, currency)}
                    </span>
                  </p>
                )}
                <p className='text-xs text-muted-foreground'>
                  Como la organización paga de forma manual, no se cobra nada ahora: el ajuste se
                  refleja en el siguiente pago.
                </p>
              </div>
            )}

            {/* Preview pago con tarjeta (desglose de líneas de Stripe) */}
            {!isLoadingPreview && !previewError && preview && !isManual && (
              <div className='space-y-3 border border-border bg-muted/30 p-4 text-sm'>
                <ul className='space-y-2'>
                  {(preview.lines ?? []).map((line, index) => (
                    <li key={index} className='flex items-baseline justify-between gap-3'>
                      <span className='text-muted-foreground'>{line.description ?? 'Ajuste'}</span>
                      <span className='font-medium tabular-nums text-foreground'>
                        {typeof line.amount === 'number' ? formatPrice(line.amount, currency) : '—'}
                      </span>
                    </li>
                  ))}
                </ul>
                <div className='flex items-baseline justify-between gap-3 border-t border-border pt-3'>
                  <span className='font-medium text-foreground'>A cobrar ahora</span>
                  <span className='text-lg font-semibold tabular-nums text-foreground'>
                    {formatPrice(preview.amount_due ?? 0, currency)}
                  </span>
                </div>
                {typeof preview.proration_date === 'number' && (
                  <p className='text-xs text-muted-foreground'>
                    Prorrateo calculado al{' '}
                    {displayFormatDate(unixSecondsToInstant(preview.proration_date), 'mediumDate')}.
                  </p>
                )}
              </div>
            )}

            {/* Aviso de plan-downgrade-guard: el plan destino no admite las unidades activas actuales */}
            {!isLoadingPreview && !previewError && unitLimitWarning && (
              <div className='flex items-start gap-3 border border-border bg-secondary p-4 text-sm text-foreground'>
                <AlertCircle className='mt-0.5 h-5 w-5 shrink-0' aria-hidden />
                <p className='font-medium'>{unitLimitWarning.message}</p>
              </div>
            )}
          </>
        )}
      </div>
    </Modal>
  )
}
