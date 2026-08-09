/**
 * Tests del motor de tracking (shared/analytics). Cubre:
 *  - sin consentimiento, trackEvent no dispara pixels NI el POST a /track
 *  - con consentimiento, dispara fbq + gtag + POST con el mismo event_id
 *  - el flujo persist/read/mark/isTracked evita re-disparar Purchase en un remount
 *  - getGaClientId parsea la cookie _ga
 */
import {
  getGaClientId,
  isPurchaseIntentTracked,
  markPurchaseIntentTracked,
  persistPurchaseIntent,
  readPurchaseIntent,
  trackEvent,
} from '@/shared/analytics/trackEvent'
import { apiClient } from '@/shared/api/apiClient'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/shared/api/apiClient', async (importActual) => {
  const actual = await importActual<typeof import('@/shared/api/apiClient')>()
  return { ...actual, apiClient: { post: vi.fn().mockResolvedValue({ data: {} }) } }
})

const mockPost = vi.mocked(apiClient.post)
const CONSENT_KEY = 'edi-consent'

function setConsent(value: 'granted' | 'denied'): void {
  localStorage.setItem(CONSENT_KEY, value)
}

function clearCookies(): void {
  document.cookie.split(';').forEach((entry) => {
    const name = entry.split('=')[0]?.trim()
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
  })
}

beforeEach(() => {
  localStorage.clear()
  clearCookies()
  mockPost.mockClear()
  window.fbq = vi.fn() as unknown as Window['fbq']
  window.gtag = vi.fn() as unknown as Window['gtag']
})

afterEach(() => {
  window.fbq = undefined
  window.gtag = undefined
})

describe('trackEvent — gate de consentimiento', () => {
  it('sin consentimiento no dispara fbq, gtag ni el POST a /track', async () => {
    setConsent('denied')

    const eventId = await trackEvent('Lead', { value: 100 })

    expect(window.fbq).not.toHaveBeenCalled()
    expect(window.gtag).not.toHaveBeenCalled()
    expect(mockPost).not.toHaveBeenCalled()
    // Igual devuelve un event_id válido para no romper callers (ej. checkout).
    expect(eventId).toMatch(/^[0-9a-f-]{36}$/)
  })

  it('con consentimiento dispara fbq + gtag + POST /track con el MISMO event_id', async () => {
    setConsent('granted')

    const eventId = await trackEvent('Lead', { value: 100 })

    expect(window.fbq).toHaveBeenCalledWith('track', 'Lead', { value: 100 }, { eventID: eventId })
    expect(window.gtag).toHaveBeenCalledWith('event', 'generate_lead', { value: 100 })
    expect(mockPost).toHaveBeenCalledTimes(1)
    const [path, body] = mockPost.mock.calls[0]
    expect(path).toBe('/track')
    expect(body).toMatchObject({ event: 'Lead', event_id: eventId, action_source: 'website' })
  })

  it('reusa un event_id explícito en vez de generar uno nuevo', async () => {
    setConsent('granted')

    const eventId = await trackEvent('Purchase', {}, undefined, 'fixed-event-id')

    expect(eventId).toBe('fixed-event-id')
    expect(window.fbq).toHaveBeenCalledWith('track', 'Purchase', {}, { eventID: 'fixed-event-id' })
  })
})

describe('purchase intent — persist/read/mark/isTracked', () => {
  it('no re-dispara Purchase en un remount simulado (mismo event_id)', async () => {
    setConsent('granted')
    persistPurchaseIntent({ eventId: 'evt-checkout-1', value: 500, currency: 'MXN' })

    // Simula lo que hace SuccessView en cada mount: leer, chequear tracked, disparar.
    const simulateMount = async () => {
      const intent = readPurchaseIntent()
      if (intent && !isPurchaseIntentTracked(intent.eventId)) {
        markPurchaseIntentTracked(intent.eventId)
        await trackEvent('Purchase', { value: intent.value, currency: intent.currency }, undefined, intent.eventId)
      }
    }

    await simulateMount() // mount inicial: confirma la compra
    await simulateMount() // remount (ej. refresh de la página)

    expect(mockPost).toHaveBeenCalledTimes(1)
  })

  it('sin intent persistido no dispara un Purchase con id aleatorio', () => {
    expect(readPurchaseIntent()).toBeNull()
  })

  it('isPurchaseIntentTracked distingue entre distintos event_id', () => {
    markPurchaseIntentTracked('evt-a')
    expect(isPurchaseIntentTracked('evt-a')).toBe(true)
    expect(isPurchaseIntentTracked('evt-b')).toBe(false)
  })
})

describe('getGaClientId', () => {
  it('parsea el client id de la cookie _ga (formato GA1.2.<clientId>)', () => {
    document.cookie = '_ga=GA1.2.123456789.987654321'
    expect(getGaClientId()).toBe('123456789.987654321')
  })

  it('devuelve undefined si no hay cookie _ga', () => {
    expect(getGaClientId()).toBeUndefined()
  })
})
