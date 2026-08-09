/**
 * Tests for ChangeBillingPlanModal: the plan-downgrade-guard warning panel
 * (REQ-02/REQ-07) plus the decision info the plan picker must show.
 *
 * Strategy: mock BillingResource.getCatalog + CheckoutSubscriptionResource
 * (preview/apply). The picker is plain buttons (no Radix Select), so no
 * portal/pointer-capture stubbing is needed to choose a plan.
 */
import { ChangeBillingPlanModal } from '@/modules/user/components/ChangeBillingPlanModal'
import { BillingResource } from '@/modules/checkoutSubscription/services/BillingResource'
import { CheckoutSubscriptionResource } from '@/modules/checkoutSubscription/services/CheckoutSubscriptionResource'
import type { IPlanCatalog } from '@/modules/checkoutSubscription/schema'
import type { IPreferentialSwapPreview } from '@/modules/tenant/billing/schema'
import { render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/checkoutSubscription/services/BillingResource', () => ({
  BillingResource: { getCatalog: vi.fn() },
}))

vi.mock('@/modules/checkoutSubscription/services/CheckoutSubscriptionResource', () => ({
  CheckoutSubscriptionResource: {
    previewBillingPlanChange: vi.fn(),
    changeBillingPlan: vi.fn(),
  },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const mockGetCatalog = vi.mocked(BillingResource.getCatalog)
const mockPreview = vi.mocked(CheckoutSubscriptionResource.previewBillingPlanChange)

const baseModule = {
  module_key: 'base',
  name: 'Plan Base',
  is_base: true,
  features: ['Cobranza completa', 'Control de accesos'],
  prices: {},
}

// Bandas DISJUNTAS y ordenadas, como el catálogo real (1–25, 26–50, 51–100…):
// `planForUnitCount` devuelve la primera que cubre el número, así que un fixture
// con bandas solapadas probaría un catálogo que no existe.
const catalog: IPlanCatalog = {
  currency: 'MXN',
  plans: [
    {
      key: 'small',
      name: 'Small',
      suggested_unit_min: 1,
      suggested_unit_max: 25,
      modules: [baseModule],
      prices: { month: { unit_amount: 45000, currency: 'MXN' } },
    },
    {
      key: 'current',
      name: 'Current',
      suggested_unit_min: 26,
      suggested_unit_max: 100,
      modules: [baseModule],
      prices: { month: { unit_amount: 95000, currency: 'MXN' } },
    },
  ],
}

function renderModal(unitCount = 6) {
  return render(
    <ChangeBillingPlanModal
      isOpen={true}
      currentPlanKey='current'
      currentInterval='month'
      unitCount={unitCount}
      onClose={vi.fn()}
      onApplied={vi.fn()}
    />
  )
}

async function selectPlan(planName: string) {
  // Por rol, no por texto: el nombre del plan también aparece en la recomendación
  // ("te toca el plan Small"), así que buscarlo suelto encuentra dos elementos.
  const option = () => screen.getByRole('radio', { name: new RegExp(planName, 'i') })
  await waitFor(() => expect(option()).toBeInTheDocument())
  option().click()
}

describe('ChangeBillingPlanModal — información para decidir', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockGetCatalog.mockResolvedValue(catalog)
  })

  it('muestra la banda de unidades y el precio de cada plan destino', async () => {
    renderModal()

    // La banda es lo que de verdad diferencia un plan de otro: sin ella el dueño
    // elige por nombre. El precio va en el intervalo contratado (mensual aquí).
    await waitFor(() => {
      expect(screen.getByText('Ideal para 1–25 unidades')).toBeInTheDocument()
    })
    expect(screen.getByText('$450')).toBeInTheDocument()
  })

  it('muestra de dónde parte el condominio: plan vigente, su banda y su precio', async () => {
    renderModal()

    await waitFor(() => {
      expect(screen.getByText('Plan Current')).toBeInTheDocument()
    })
    expect(screen.getByText('Ideal para 26–100 unidades')).toBeInTheDocument()
    expect(screen.getByText('$950')).toBeInTheDocument()
    expect(screen.getByText(/6 unidades/)).toBeInTheDocument()
  })

  it('señala el plan que le toca por su número de unidades', async () => {
    // 6 unidades caen en la banda de Small (1–25), que NO es el plan vigente:
    // es justo el caso en que el dueño está pagando de más y no lo sabe.
    renderModal(6)

    await waitFor(() => {
      expect(screen.getByText('Te toca')).toBeInTheDocument()
    })
    expect(screen.getByText(/te toca el plan/i)).toBeInTheDocument()
  })

  it('no recomienda nada ni alarma cuando el condominio aún no tiene unidades', async () => {
    renderModal(0)

    await waitFor(() => {
      expect(screen.getByText(/todavía no registras unidades/i)).toBeInTheDocument()
    })
    expect(screen.queryByText('Te toca')).not.toBeInTheDocument()
    expect(screen.queryByText(/plan a medida/i)).not.toBeInTheDocument()
  })

  it('anuncia las funciones UNA vez cuando todos los planes traen lo mismo', async () => {
    renderModal()

    await waitFor(() => {
      expect(screen.getByText('Esto viene en cualquier plan')).toBeInTheDocument()
    })
    // Una sola entrada por función: repetirla por plan insinuaría una diferencia
    // que en este modelo ("todo es base") no existe.
    expect(screen.getAllByText('Cobranza completa')).toHaveLength(1)
  })
})

describe('ChangeBillingPlanModal — plan-downgrade-guard warning panel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockGetCatalog.mockResolvedValue(catalog)
  })

  it('renders the warning message and disables "Confirmar cambio" when unit_limit_warning is present', async () => {
    const preview: IPreferentialSwapPreview = {
      manual: true,
      message: 'El nuevo precio aplica en el próximo ciclo.',
      new_price: 45000,
      unit_limit_warning: {
        message:
          'Tu condominio tiene 6 unidades activas y el plan Small admite hasta 5. El plan que se ajusta es Current.',
        plan_name: 'Small',
        unit_limit: 5,
        active_units: 6,
        suggested_plan_name: 'Current',
      },
    }
    mockPreview.mockResolvedValue(preview)

    renderModal()

    await selectPlan('Small')

    await waitFor(() => {
      expect(screen.getByText(/6 unidades activas/i)).toBeInTheDocument()
    })

    const confirmButton = screen.getByRole('button', { name: /confirmar cambio/i })
    expect(confirmButton).toBeDisabled()
  })

  it('keeps "Confirmar cambio" enabled and renders no warning panel when unit_limit_warning is absent', async () => {
    const preview: IPreferentialSwapPreview = {
      manual: true,
      message: 'El nuevo precio aplica en el próximo ciclo.',
      new_price: 45000,
    }
    mockPreview.mockResolvedValue(preview)

    renderModal()

    await selectPlan('Small')

    await waitFor(() => {
      expect(screen.getByText(/el nuevo precio aplica en el próximo ciclo/i)).toBeInTheDocument()
    })

    expect(screen.queryByText(/unidades activas/i)).not.toBeInTheDocument()

    const confirmButton = screen.getByRole('button', { name: /confirmar cambio/i })
    expect(confirmButton).not.toBeDisabled()
  })
})
