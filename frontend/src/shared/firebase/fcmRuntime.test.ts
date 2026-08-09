import { fcmRuntime, resetFcmGlobals } from '@/shared/firebase/fcmRuntime'
import { describe, expect, it, vi } from 'vitest'

describe('resetFcmGlobals', () => {
  it('calls the stored unsubscribe and clears all runtime state', () => {
    const unsubscribe = vi.fn()
    fcmRuntime.initPromise = Promise.resolve(true)
    fcmRuntime.listenerSet = true
    fcmRuntime.unsubscribe = unsubscribe
    fcmRuntime.navigate = vi.fn()

    resetFcmGlobals()

    expect(unsubscribe).toHaveBeenCalledTimes(1)
    expect(fcmRuntime.initPromise).toBeNull()
    expect(fcmRuntime.listenerSet).toBe(false)
    expect(fcmRuntime.unsubscribe).toBeNull()
    expect(fcmRuntime.navigate).toBeNull()
  })

  it('is safe to call when nothing is set', () => {
    resetFcmGlobals()
    expect(() => resetFcmGlobals()).not.toThrow()
    expect(fcmRuntime.listenerSet).toBe(false)
    expect(fcmRuntime.unsubscribe).toBeNull()
  })

  it('swallows errors thrown by unsubscribe and still clears state', () => {
    fcmRuntime.listenerSet = true
    fcmRuntime.unsubscribe = () => {
      throw new Error('boom')
    }

    expect(() => resetFcmGlobals()).not.toThrow()
    expect(fcmRuntime.unsubscribe).toBeNull()
    expect(fcmRuntime.listenerSet).toBe(false)
  })
})
