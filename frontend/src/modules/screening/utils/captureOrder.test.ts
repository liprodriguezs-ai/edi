import type { IBankArea } from '@/modules/screening/schema'
import { describe, expect, it } from 'vitest'
import { orderAreasForCapture } from './captureOrder'

const area = (code: string): IBankArea => ({ development_area_id: code, code, name: code, items: [] })

describe('orderAreasForCapture', () => {
  it('follows the official form: alert signs, development axes, then neurological exam and alarm signs', () => {
    // Server order seen in the app: LE, MG, SO, MF.
    const ordered = orderAreasForCapture(['LE', 'EN', 'MG', 'ALA', 'SO', 'ALE', 'CO', 'MF'].map(area))

    expect(ordered.map((a) => a.code)).toEqual(['ALE', 'MG', 'MF', 'LE', 'SO', 'CO', 'EN', 'ALA'])
  })
})
