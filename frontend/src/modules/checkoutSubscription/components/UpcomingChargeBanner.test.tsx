/**
 * Tests for UpcomingChargeBanner — the panel-wide warning that the first charge
 * is coming.
 *
 * Why this file exists: the banner had NO test at all, and it is one of the two
 * places that render `trial_days_left`. That number is API-owned
 * (trial-countdown-utility): the banner must PRINT it, never re-derive it. Both
 * copies of the old inline formula lived here and in SelectPlanPage, and they
 * had already drifted apart once before being fixed by hand — so "the component
 * shows exactly the number the API sent" is the property worth pinning.
 */
import { UpcomingChargeBanner } from '@/modules/checkoutSubscription/components/UpcomingChargeBanner'
import type { SessionProfile, SubscriptionState } from '@/shared/auth/Session'
import { sessionStore } from '@/shared/auth/sessionStore'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it } from 'vitest'

function setSubscription(subscription: SubscriptionState) {
  const profile: SessionProfile = {
    user: { id: 'u-1', email: 'owner@test.mx', name: 'Owner' },
    abilities: [],
    roles: [ERoleUserSlug.ACCOUNT_OWNER],
    subscription,
  }
  sessionStore.getState().setProfile(profile)
}

function inDays(days: number): string {
  return new Date(Date.now() + days * 24 * 60 * 60 * 1000).toISOString()
}

function renderBanner() {
  return render(
    <MemoryRouter>
      <UpcomingChargeBanner />
    </MemoryRouter>
  )
}

/** Prueba de Stripe que se convierte sola — el único caso que el banner anuncia. */
function autoChargingTrial(overrides: Partial<SubscriptionState> = {}): SubscriptionState {
  return {
    status: 'trial',
    is_blocked: false,
    is_owner: true,
    trial_ends_at: inDays(3),
    trial_days_left: 3,
    trial_auto_charges: true,
    ...overrides,
  } as SubscriptionState
}

describe('UpcomingChargeBanner', () => {
  beforeEach(() => {
    sessionStore.getState().clearSession()
  })

  it('prints the day count the API sent, verbatim', () => {
    setSubscription(autoChargingTrial({ trial_days_left: 3 }))
    renderBanner()

    expect(screen.getByText(/termina en 3 días/i)).toBeInTheDocument()
  })

  it('follows the API even when the count disagrees with the date', () => {
    // La fecha dice ~6 días, la API dice 2. El banner debe decir 2: la API es la
    // dueña del conteo. Si el componente volviera a calcularlo desde la fecha
    // —el cálculo cruzado que se eliminó— este test lo detecta.
    setSubscription(autoChargingTrial({ trial_ends_at: inDays(6), trial_days_left: 2 }))
    renderBanner()

    expect(screen.getByText(/termina en 2 días/i)).toBeInTheDocument()
    expect(screen.queryByText(/termina en 6 días/i)).not.toBeInTheDocument()
  })

  it('singularises one day', () => {
    setSubscription(autoChargingTrial({ trial_days_left: 1, trial_ends_at: inDays(1) }))
    renderBanner()

    expect(screen.getByText(/termina en 1 día\b/i)).toBeInTheDocument()
  })

  it('switches to today copy at zero, without printing "0 días"', () => {
    setSubscription(autoChargingTrial({ trial_days_left: 0, trial_ends_at: inDays(0) }))
    renderBanner()

    expect(screen.getByText(/hoy termina tu prueba/i)).toBeInTheDocument()
    expect(screen.queryByText(/0 días/)).not.toBeInTheDocument()
  })

  it('stays hidden outside the warning window', () => {
    // 30 días: hay prueba, pero no hay nada urgente que anunciar todavía.
    // El estado se fija ANTES de renderizar: al revés el banner saldría vacío
    // por no haber sesión, y el test pasaría sin probar la ventana de aviso.
    setSubscription(autoChargingTrial({ trial_days_left: 30, trial_ends_at: inDays(30) }))
    const { container } = renderBanner()

    expect(container).toBeEmptyDOMElement()
  })

  it('stays hidden for a courtesy trial that never charges', () => {
    // trial_auto_charges=false: no hay cargo que avisar. Anunciarlo asustaría a
    // quien no va a pagar nada.
    setSubscription(autoChargingTrial({ trial_auto_charges: false }))
    const { container } = renderBanner()

    expect(container).toBeEmptyDOMElement()
  })

  it('stays hidden when the API sent no count', () => {
    setSubscription(autoChargingTrial({ trial_days_left: null }))
    const { container } = renderBanner()

    expect(container).toBeEmptyDOMElement()
  })
})
