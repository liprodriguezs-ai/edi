import type { IBillingEntitlement } from '@/modules/tenant/billing/schema'
import { isActiveEntitlement } from '@/modules/tenant/billing/billingFormat'
import { afterEach, describe, expect, it, vi } from 'vitest'

const entitlement = (expires_at: string | null) =>
  ({ expires_at }) as unknown as IBillingEntitlement

describe('isActiveEntitlement', () => {
  afterEach(() => vi.useRealTimers())

  it('compara el instante de vencimiento contra ahora (sin vencimiento = vigente)', () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(new Date('2026-10-21T03:00:00Z'))

    expect(isActiveEntitlement(entitlement(null))).toBe(true)
    expect(isActiveEntitlement(entitlement('2026-10-21T03:00:00.000000Z'))).toBe(true)
    expect(isActiveEntitlement(entitlement('2026-10-21T02:59:59.000000Z'))).toBe(false)
    expect(isActiveEntitlement(entitlement('2026-10-22'))).toBe(true)
    expect(isActiveEntitlement(entitlement('2026-10-20'))).toBe(false)
  })
})
