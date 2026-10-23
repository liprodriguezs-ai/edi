import { resolveDateRange } from '@/shared/components/filters/DateRangeFilter'
import { setTenantTimeZone } from '@/shared/utils/dateHelper'
import { afterEach, describe, expect, it } from 'vitest'

// 1 nov 2026 03:00 UTC = sáb 31 oct 21:00 en CDMX = dom 1 nov 12:00 en Tokio.
const NOW = '2026-11-01T03:00:00Z'

describe('resolveDateRange', () => {
  afterEach(() => setTenantTimeZone(null))

  it('resuelve los atajos con el día civil de la zona del tenant', () => {
    setTenantTimeZone('Asia/Tokyo')

    expect(resolveDateRange({ from: null, to: null, shortcut: 'today' }, NOW)).toEqual({
      from: '2026-11-01',
      to: '2026-11-01',
    })
    expect(resolveDateRange({ from: null, to: null, shortcut: 'this_month' }, NOW)).toEqual({
      from: '2026-11-01',
      to: '2026-11-30',
    })

    setTenantTimeZone('America/Mexico_City')
    expect(resolveDateRange({ from: null, to: null, shortcut: 'overdue' }, NOW)).toEqual({
      from: null,
      to: '2026-10-30',
    })
    expect(resolveDateRange({ from: null, to: null, shortcut: 'this_week' }, NOW)).toEqual({
      from: '2026-10-26',
      to: '2026-11-01',
    })
  })

  it('deja pasar el rango manual sin tocarlo', () => {
    expect(resolveDateRange({ from: '2026-10-01', to: '2026-10-15' })).toEqual({
      from: '2026-10-01',
      to: '2026-10-15',
    })
  })
})
