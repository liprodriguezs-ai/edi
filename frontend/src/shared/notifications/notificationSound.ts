/**
 * Notification sound — a short, pleasant 2-note chime played when an in-app
 * notification arrives (foreground push + the in-app toast).
 *
 * - Source: `/sounds/notification.wav`. Falls back to a synthesized AudioContext
 *   beep if the WAV can't be loaded/played.
 * - Autoplay policy: browsers block audio until the user interacts, so we PRIME
 *   an <audio> element on the first click/keydown (`unlockAudioOnFirstGesture`).
 * - Mute: a client-side, per-browser toggle stored in localStorage
 *   (`notif_sound_muted`). No backend involved.
 */
import { useCallback, useState } from 'react'

const MUTE_KEY = 'notif_sound_muted'
const SOUND_URL = '/sounds/notification.wav'

let primedAudio: HTMLAudioElement | null = null
let unlockBound = false
// Reused across beeps so we don't leak an AudioContext on every notification.
let fallbackCtx: AudioContext | null = null

function getFallbackCtx(): AudioContext | null {
  const Ctx =
    window.AudioContext ??
    (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext
  if (!Ctx) {
    return null
  }
  if (!fallbackCtx || fallbackCtx.state === 'closed') {
    fallbackCtx = new Ctx()
  }
  return fallbackCtx
}

export function isNotificationSoundMuted(): boolean {
  try {
    return localStorage.getItem(MUTE_KEY) === 'true'
  } catch {
    return false
  }
}

export function setNotificationSoundMuted(muted: boolean): void {
  try {
    localStorage.setItem(MUTE_KEY, muted ? 'true' : 'false')
  } catch {
    // Storage unavailable (private mode) — silently ignore.
  }
}

/** Primes an <audio> element on the first user gesture to satisfy autoplay policies. */
export function unlockAudioOnFirstGesture(): void {
  if (unlockBound || typeof window === 'undefined') {
    return
  }
  unlockBound = true

  const prime = () => {
    try {
      primedAudio = new Audio(SOUND_URL)
      primedAudio.load()
    } catch {
      // ignore — playback will fall back to the AudioContext beep.
    }
    window.removeEventListener('click', prime)
    window.removeEventListener('keydown', prime)
  }

  window.addEventListener('click', prime, { once: true })
  window.addEventListener('keydown', prime, { once: true })
}

/** Synthesized fallback used when the WAV can't be played. Reuses one AudioContext. */
function fallbackBeep(): void {
  try {
    const ctx = getFallbackCtx()
    if (!ctx) {
      return
    }
    const oscillator = ctx.createOscillator()
    const gain = ctx.createGain()
    oscillator.connect(gain)
    gain.connect(ctx.destination)
    oscillator.frequency.setValueAtTime(800, ctx.currentTime)
    oscillator.frequency.setValueAtTime(600, ctx.currentTime + 0.1)
    gain.gain.setValueAtTime(0.3, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3)
    oscillator.start(ctx.currentTime)
    oscillator.stop(ctx.currentTime + 0.3)
    // Free the oscillator graph when the tone ends (the context itself is reused).
    oscillator.addEventListener('ended', () => {
      oscillator.disconnect()
      gain.disconnect()
    })
  } catch {
    // ignore — audio simply won't play.
  }
}

/** Plays the notification chime unless the user has muted it. */
export function playNotificationSound(): void {
  if (isNotificationSoundMuted()) {
    return
  }
  try {
    const audio = primedAudio
      ? (primedAudio.cloneNode() as HTMLAudioElement)
      : new Audio(SOUND_URL)
    audio.volume = 0.5
    const played = audio.play()
    if (played && typeof played.catch === 'function') {
      played.catch(() => fallbackBeep())
    }
  } catch {
    fallbackBeep()
  }
}

/** React binding for the mute toggle (reads/writes localStorage). */
export function useNotificationSoundMuted(): [boolean, (_muted: boolean) => void] {
  const [muted, setMutedState] = useState<boolean>(() => isNotificationSoundMuted())

  const setMuted = useCallback((value: boolean) => {
    setNotificationSoundMuted(value)
    setMutedState(value)
  }, [])

  return [muted, setMuted]
}
