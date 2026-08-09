import {
  contactMailtoHref,
  contactWhatsappHref,
  isContactSalesPlan,
  isPurchasablePlan,
} from '@/modules/checkoutSubscription/planSelection'
import {
  type BillingInterval,
  type IBillingPlan,
  type IPlanCatalog,
  type IPlanQuote,
} from '@/modules/checkoutSubscription/schema'
import { BillingResource } from '@/modules/checkoutSubscription/services/BillingResource'
import { getGaClientId, persistPurchaseIntent, trackEvent } from '@/shared/analytics/trackEvent'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'

/** Debounce (ms) antes de recotizar tras un cambio. */
const QUOTE_DEBOUNCE_MS = 250

export interface UseBillingQuoterResult {
  catalog: IPlanCatalog | null
  plans: IBillingPlan[]
  isLoadingCatalog: boolean
  catalogError: string | null

  /** Intervalo de facturación seleccionado. Renombrado desde `interval` para evitar
   * sombrear `window.setInterval` al desestructurar. */
  billingInterval: BillingInterval
  setBillingInterval: (_interval: BillingInterval) => void

  /** Plan actualmente elegido. */
  selectedPlanKey: string | null
  selectedPlan: IBillingPlan | null
  selectPlan: (_key: string) => void

  /**
   * true cuando el plan elegido es de venta consultiva ("a medida"): no se cotiza,
   * no se muestra precio y no se puede contratar en línea.
   */
  isContactSales: boolean
  /** `mailto:` de venta consultiva (null si el catálogo no trae email). */
  contactMailto: string | null
  /** Enlace de WhatsApp (null si el catálogo no trae número). */
  contactWhatsapp: string | null
  /**
   * false cuando el plan elegido tiene precio de lista pero ninguna fila está
   * sincronizada con Stripe (falla operativa, D6). El botón "Contratar" debe
   * deshabilitarse — contratar igual solo produciría el 422 `no_billable_price`.
   */
  isPurchasable: boolean

  quote: IPlanQuote | null
  isQuoting: boolean
  quoteError: boolean
  /** Reintenta la cotización manualmente. */
  retryQuote: () => void

  checkout: () => Promise<void>
  isCheckingOut: boolean
  checkoutError: string | null
  clearCheckoutError: () => void
}

export function useBillingQuoter(): UseBillingQuoterResult {
  const [searchParams] = useSearchParams()
  // Capturamos la key del plan de la URL SOLO al montar, TAL CUAL viene. El effect
  // de loadCatalog se ejecuta una sola vez y lee este ref sin necesitarlo como
  // dependencia (los refs son estables).
  //
  // No se pre-valida contra ninguna lista local: la única autoridad sobre qué keys
  // existen es el catálogo, y abajo se resuelve con `data.plans.find(...)` — una key
  // desconocida simplemente no encuentra plan y cae a `plans[0]`. El pre-filtro que
  // vivía aquí duplicaba esa validación contra una constante escrita a mano, así que
  // un plan nuevo del backend quedaba ignorado hasta editar el frontend.
  const urlPlanKeyRef = useRef<string | null>(searchParams.get('plan'))

  const [catalog, setCatalog] = useState<IPlanCatalog | null>(null)
  const [isLoadingCatalog, setIsLoadingCatalog] = useState(true)
  const [catalogError, setCatalogError] = useState<string | null>(null)

  const [billingInterval, setBillingIntervalState] = useState<BillingInterval>('month')
  const [selectedPlanKey, setSelectedPlanKey] = useState<string | null>(null)

  const [quote, setQuote] = useState<IPlanQuote | null>(null)
  const [isQuoting, setIsQuoting] = useState(false)
  const [quoteError, setQuoteError] = useState(false)

  const [isCheckingOut, setIsCheckingOut] = useState(false)
  const [checkoutError, setCheckoutError] = useState<string | null>(null)
  /**
   * El backend respondió `contact_sales` para el plan elegido aunque el catálogo no
   * lo marcaba así (catálogo cacheado). El backend manda: mostramos igual la card de
   * contacto. Se resetea al cambiar de plan.
   */
  const [contactSalesFromApi, setContactSalesFromApi] = useState(false)

  const [quoteRetryToken, setQuoteRetryToken] = useState(0)
  const quoteTokenRef = useRef(0)

  const plans = useMemo(() => catalog?.plans ?? [], [catalog])
  const selectedPlan = useMemo(
    () => plans.find((plan) => plan.key === selectedPlanKey) ?? null,
    [plans, selectedPlanKey]
  )
  const isContactSales = isContactSalesPlan(selectedPlan)
  const isPurchasable = isPurchasablePlan(selectedPlan)

  const contactMailto = useMemo(() => contactMailtoHref(catalog), [catalog])
  const contactWhatsapp = useMemo(() => contactWhatsappHref(catalog), [catalog])

  // Carga del catálogo (una sola vez). Preselecciona el plan indicado por ?plan=<key>
  // si existe en el catálogo; si no, usa el primer plan como fallback.
  useEffect(() => {
    let cancelled = false
    const loadCatalog = async () => {
      try {
        setIsLoadingCatalog(true)
        const data = await BillingResource.getCatalog()
        if (cancelled) return
        setCatalog(data)
        const urlKey = urlPlanKeyRef.current
        const planFromUrl = urlKey ? (data.plans.find((p) => p.key === urlKey) ?? null) : null
        const initialPlan = planFromUrl ?? data.plans[0] ?? null
        setSelectedPlanKey(initialPlan?.key ?? null)
      } catch {
        if (!cancelled)
          setCatalogError('No pudimos cargar el catálogo. Inténtalo de nuevo en unos segundos.')
      } finally {
        if (!cancelled) setIsLoadingCatalog(false)
      }
    }
    void loadCatalog()
    return () => {
      cancelled = true
    }
  }, [])

  // Cotización en vivo (debounced) ante cambios de plan/intervalo. Un plan de venta
  // consultiva no se cotiza: el backend responde 422 `contact_sales`, así que ni se pide.
  useEffect(() => {
    if (!catalog || !selectedPlanKey) return
    if (isContactSales) {
      setQuote(null)
      setQuoteError(false)
      return
    }
    let active = true
    const token = (quoteTokenRef.current += 1)

    const handle = setTimeout(() => {
      void (async () => {
        setIsQuoting(true)
        setQuoteError(false)
        try {
          const result = await BillingResource.quote({
            plan_key: selectedPlanKey,
            interval: billingInterval,
          })
          if (!active || token !== quoteTokenRef.current) return
          setQuote(result)
        } catch {
          if (!active || token !== quoteTokenRef.current) return
          setQuoteError(true)
        } finally {
          if (active && token === quoteTokenRef.current) setIsQuoting(false)
        }
      })()
    }, QUOTE_DEBOUNCE_MS)

    return () => {
      active = false
      clearTimeout(handle)
    }
  }, [catalog, selectedPlanKey, billingInterval, isContactSales, quoteRetryToken])

  const selectPlan = useCallback((key: string) => {
    setSelectedPlanKey(key)
    setCheckoutError(null)
    setContactSalesFromApi(false)
  }, [])

  const setBillingInterval = useCallback((next: BillingInterval) => {
    setBillingIntervalState(next)
  }, [])

  const retryQuote = useCallback(() => setQuoteRetryToken((token) => token + 1), [])

  const clearCheckoutError = useCallback(() => setCheckoutError(null), [])

  const handleCheckoutError = useCallback((err: unknown) => {
    if (err instanceof ApiError) {
      const code = err.payload?.code
      if (code === 'not_account_owner') {
        setCheckoutError(
          'Solo el propietario de la cuenta puede contratar un plan. Pide al propietario que complete la contratación.'
        )
        return
      }
      if (code === 'owner_unresolved') {
        setCheckoutError(
          'No pudimos identificar al propietario de la cuenta. Contacta a soporte para resolverlo.'
        )
        return
      }
      if (code === 'already_subscribed') {
        setCheckoutError('Ya tienes una suscripción activa.')
        return
      }
      if (code === 'contact_sales') {
        // El backend confirma que este plan no se vende en línea: en vez de un toast
        // genérico, la UI cambia a la card "Contáctanos".
        setContactSalesFromApi(true)
        return
      }
      if (code === 'no_billable_price') {
        // D6 — falla operativa (plan con precio pero sin sincronizar a Stripe), no
        // una decisión del usuario: mensaje accionable en vez del toast genérico.
        setCheckoutError(
          'Este plan no está disponible para contratar en este momento. Intenta con otro plan o contáctanos.'
        )
        return
      }
      const message = formatApiErrorMessage(err)
      toast.error(message.title, message.description)
      return
    }
    toast.error('Error', 'No se pudo iniciar el proceso de contratación.')
  }, [])

  const checkout = useCallback(async () => {
    if (!selectedPlanKey || isContactSales) return
    setIsCheckingOut(true)
    setCheckoutError(null)
    try {
      const value = quote?.total !== undefined ? quote.total / 100 : undefined
      const currency = quote?.currency ?? catalog?.currency

      // event_id se genera UNA vez acá y viaja por dos caminos: (1) al checkout
      // request → metadata del Checkout Session de Stripe → Purchase del webhook;
      // (2) persistido en localStorage → Purchase del navegador al volver de Stripe
      // (ver SelectPlanPage). Mismo id en ambos → Meta dedup.
      const eventId = await trackEvent('InitiateCheckout', { value, currency })
      persistPurchaseIntent({ eventId, value, currency })

      const url = await BillingResource.checkout({
        plan_key: selectedPlanKey,
        interval: billingInterval,
        event_id: eventId,
        ga_client_id: getGaClientId(),
      })
      if (url) {
        window.location.href = url
        return
      }
    } catch (err) {
      handleCheckoutError(err)
    } finally {
      setIsCheckingOut(false)
    }
  }, [selectedPlanKey, isContactSales, billingInterval, quote, catalog, handleCheckoutError])

  return {
    catalog,
    plans,
    isLoadingCatalog,
    catalogError,
    billingInterval,
    setBillingInterval,
    selectedPlanKey,
    selectedPlan,
    selectPlan,
    isContactSales: isContactSales || contactSalesFromApi,
    contactMailto,
    contactWhatsapp,
    isPurchasable,
    quote,
    isQuoting,
    quoteError,
    retryQuote,
    checkout,
    isCheckingOut,
    checkoutError,
    clearCheckoutError,
  }
}
