/**
 * Tests for SelectPlanPage's blocked-state copy (D8 / copy.md §8): the three
 * distinct blocked causes ("never chose a plan", "trial ended", "charge
 * failed") must render distinct headlines, and the non-owner member view must
 * show its own shared "paused" copy.
 *
 * Strategy: mock BillingResource.getCatalog with a never-resolving promise so
 * PlanBuilder stays in its skeleton — this test only asserts the headline
 * block above it, not the plan grid itself.
 */
import { SelectPlanPage } from '@/modules/checkoutSubscription/SelectPlanPage'
import type { SessionProfile, SubscriptionState } from '@/shared/auth/Session'
import { sessionStore } from '@/shared/auth/sessionStore'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// El armador de planes se stubbea: estos tests afirman CUÁNDO aparece (jerarquía
// de la pantalla), no qué contiene. Un testid lo hace inequívoco.
vi.mock('@/modules/checkoutSubscription/components/PlanBuilder', () => ({
  PlanBuilder: () => <div data-testid='plan-builder' />,
}))

vi.mock('@/modules/checkoutSubscription/services/BillingResource', () => ({
  BillingResource: {
    getCatalog: vi.fn(() => new Promise(() => {})),
    quote: vi.fn(),
    checkout: vi.fn(),
  },
}))

vi.mock('@/modules/checkoutSubscription/services/CheckoutSubscriptionResource', () => ({
  CheckoutSubscriptionResource: {
    getPortalUrl: vi.fn(),
    // Se consulta al entrar en modo "cambiar plan" para preseleccionar el plan
    // vigente del condominio.
    getMySubscription: vi.fn(() => Promise.resolve({ currentPlan: { key: 'esencial' } })),
  },
}))

function makeProfile(subscription: SubscriptionState): SessionProfile {
  return {
    user: { id: 'u-1', email: 'owner@test.mx', name: 'Owner' },
    abilities: [],
    roles: [ERoleUserSlug.ACCOUNT_OWNER],
    subscription,
  }
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/select-plan']}>
      <SelectPlanPage />
    </MemoryRouter>
  )
}

describe('SelectPlanPage — blocked-state copy (D8)', () => {
  beforeEach(() => {
    sessionStore.getState().clearSession()
  })

  it('state (a): never chose a plan', () => {
    sessionStore
      .getState()
      .setProfile(makeProfile({ status: 'none', is_blocked: true, is_owner: true }))
    renderPage()

    expect(screen.getByText('Elige tu plan para empezar')).toBeInTheDocument()
    expect(screen.getByText(/30 días gratis/)).toBeInTheDocument()
  })

  it('state (b): trial ended without contracting', () => {
    sessionStore.getState().setProfile(
      makeProfile({
        status: 'expired',
        is_blocked: true,
        is_owner: true,
        payment_issue: false,
      })
    )
    renderPage()

    expect(screen.getByText('Tu prueba terminó')).toBeInTheDocument()
  })

  it('state (c): charge failed — distinct copy + billing-portal CTA', () => {
    sessionStore.getState().setProfile(
      makeProfile({
        status: 'expired',
        is_blocked: true,
        is_owner: true,
        payment_issue: true,
      })
    )
    renderPage()

    expect(screen.getByText('No pudimos procesar tu pago')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /actualizar mi tarjeta/i })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /cambiar de plan/i })).toBeInTheDocument()
  })

  it('non-owner member sees the shared "paused" copy regardless of cause', () => {
    sessionStore.getState().setProfile(
      makeProfile({
        status: 'expired',
        is_blocked: true,
        is_owner: false,
        payment_issue: true,
      })
    )
    renderPage()

    expect(screen.getByText('El acceso está en pausa')).toBeInTheDocument()
  })
})

/**
 * D7 follow-up (team-lead review): a Stripe trial already has a plan + card
 * and auto-charges on conversion — the banner must NOT tell that owner to
 * "contrata un plan". A local (card-free) trial genuinely has neither, so it
 * keeps that call to action. Both report status='trial'; only
 * `trial_auto_charges` tells them apart.
 */
describe('SelectPlanPage — trial banner auto-charge copy', () => {
  beforeEach(() => {
    sessionStore.getState().clearSession()
  })

  const trialEndsAt = new Date(Date.now() + 1000 * 60 * 60 * 24 * 10).toISOString()
  // API-owned (trial-countdown-utility): the component RENDERS this number and
  // never derives it.
  //
  // The count DISAGREES with the date on purpose (date says ~10 days, API says
  // 4). With matching values the assertion below would pass even for a component
  // that recomputed the count from the date — which is exactly the cross-layer
  // calculation this change removed. Disagreement is what gives it teeth.
  const trialDaysLeft = 4

  it('Stripe trial (trial_auto_charges=true): no "contrata un plan" CTA, states the auto-charge', () => {
    sessionStore.getState().setProfile(
      makeProfile({
        status: 'trial',
        is_blocked: false,
        is_owner: true,
        trial_ends_at: trialEndsAt,
        trial_days_left: trialDaysLeft,
        trial_auto_charges: true,
      })
    )
    renderPage()

    expect(screen.getByText(/primer cargo/i)).toBeInTheDocument()
    expect(screen.queryByText(/contrata un plan/i)).not.toBeInTheDocument()

    // El número lo manda la API y la pantalla sólo lo pinta. El fixture hace que
    // la fecha (~10 días) contradiga al conteo (4): si la pantalla volviera a
    // calcularlo desde la fecha, diría "10 días" y esta aserción fallaría.
    expect(screen.getByText(new RegExp(`${trialDaysLeft} días`))).toBeInTheDocument()
    expect(screen.queryByText(/10 días/)).not.toBeInTheDocument()
  })

  it('local trial (trial_auto_charges=false): keeps the "contrata un plan" CTA', () => {
    sessionStore.getState().setProfile(
      makeProfile({
        status: 'trial',
        is_blocked: false,
        is_owner: true,
        trial_ends_at: trialEndsAt,
        trial_days_left: trialDaysLeft,
        trial_auto_charges: false,
      })
    )
    renderPage()

    // "Contrata un plan" aparece dos veces a propósito (subtítulo del encabezado y
    // banner del aviso): en la prueba de cortesía contratar SÍ es la tarea, así que
    // getAllByText en vez de getByText — la ambigüedad es intencional, no un descuido.
    expect(screen.getAllByText(/contrata un plan/i).length).toBeGreaterThan(0)
    expect(screen.queryByText(/primer cargo/i)).not.toBeInTheDocument()
  })

  it('local trial: shows the plan picker up front, because contracting IS the task', () => {
    sessionStore.getState().setProfile(
      makeProfile({
        status: 'trial',
        is_blocked: false,
        is_owner: true,
        trial_ends_at: trialEndsAt,
        trial_days_left: trialDaysLeft,
        trial_auto_charges: false,
      })
    )
    renderPage()

    expect(screen.getByTestId('plan-builder')).toBeInTheDocument()
  })

  it('stripe trial: hides the plan picker until the owner asks for it', () => {
    sessionStore.getState().setProfile(
      makeProfile({
        status: 'trial',
        is_blocked: false,
        is_owner: true,
        trial_ends_at: trialEndsAt,
        trial_days_left: trialDaysLeft,
        trial_auto_charges: true,
      })
    )
    renderPage()

    // Nada que resolver ⇒ el armador de planes NO compite con la fecha del cargo.
    expect(screen.queryByTestId('plan-builder')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /cambiar de plan/i }))

    expect(screen.getByTestId('plan-builder')).toBeInTheDocument()
  })

  it('payment failed: leads with updating the card, not with the plan picker', () => {
    sessionStore.getState().setProfile(
      makeProfile({
        status: 'expired',
        is_blocked: true,
        is_owner: true,
        payment_issue: true,
      })
    )
    renderPage()

    expect(screen.getByRole('button', { name: /actualizar mi tarjeta/i })).toBeInTheDocument()
    // El armador debajo del mensaje sugería "vuelve a elegir plan", que es la
    // acción equivocada cuando lo único roto es la tarjeta.
    expect(screen.queryByTestId('plan-builder')).not.toBeInTheDocument()
  })
})
