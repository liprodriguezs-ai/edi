import {
  isNotificationSoundMuted,
  playNotificationSound,
  setNotificationSoundMuted,
} from '@/shared/notifications/notificationSound'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

describe('notification sound mute toggle', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('defaults to NOT muted when nothing is stored', () => {
    expect(isNotificationSoundMuted()).toBe(false)
  })

  it('persists the muted flag to localStorage', () => {
    setNotificationSoundMuted(true)
    expect(localStorage.getItem('notif_sound_muted')).toBe('true')
    expect(isNotificationSoundMuted()).toBe(true)

    setNotificationSoundMuted(false)
    expect(localStorage.getItem('notif_sound_muted')).toBe('false')
    expect(isNotificationSoundMuted()).toBe(false)
  })
})

describe('playNotificationSound', () => {
  let playSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    localStorage.clear()
    playSpy = vi
      .spyOn(window.HTMLMediaElement.prototype, 'play')
      .mockResolvedValue(undefined)
  })

  afterEach(() => {
    playSpy.mockRestore()
  })

  it('does nothing (no playback) when muted', () => {
    setNotificationSoundMuted(true)
    playNotificationSound()
    expect(playSpy).not.toHaveBeenCalled()
  })

  it('plays the chime when not muted', () => {
    setNotificationSoundMuted(false)
    playNotificationSound()
    expect(playSpy).toHaveBeenCalledTimes(1)
  })
})
