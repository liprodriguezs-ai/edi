import { homeDestination } from '@/app/router/homeDestination'
import { describe, expect, it } from 'vitest'

describe('homeDestination', () => {
  it('sends clinical staff who can read the agenda to /agenda', () => {
    expect(homeDestination(['administrator'], true)).toBe('/agenda')
    expect(homeDestination(['account.owner'], true)).toBe('/agenda')
    expect(homeDestination([], true)).toBe('/agenda')
  })

  it('keeps the platform super-admin on its own page', () => {
    expect(homeDestination(['admin.super'], true)).toBe('/user')
  })

  it('keeps the previous role route when the agenda is not readable', () => {
    expect(homeDestination(['administrator'], false)).toBe('/dashboard')
    expect(homeDestination([], false)).toBe('/dashboard')
  })
})
