import {
  buildFirebaseSwUrl,
  getFirebaseWebConfig,
  type FirebaseWebConfig,
} from '@/shared/firebase/firebaseEnv'
import { afterEach, describe, expect, it, vi } from 'vitest'

const sampleConfig: FirebaseWebConfig = {
  apiKey: 'test-api-key',
  authDomain: 'edi-475fa.firebaseapp.com',
  projectId: 'edi-475fa',
  storageBucket: 'edi-475fa.firebasestorage.app',
  messagingSenderId: '1234567890',
  appId: '1:1234567890:web:abcdef',
}

describe('buildFirebaseSwUrl', () => {
  it('encodes the public web config as query params on the SW path', () => {
    const url = buildFirebaseSwUrl('/firebase-messaging-sw.js', sampleConfig)
    const parsed = new URL(url, 'https://app.example.com')

    expect(parsed.pathname).toBe('/firebase-messaging-sw.js')
    expect(parsed.searchParams.get('apiKey')).toBe('test-api-key')
    expect(parsed.searchParams.get('authDomain')).toBe('edi-475fa.firebaseapp.com')
    expect(parsed.searchParams.get('projectId')).toBe('edi-475fa')
    expect(parsed.searchParams.get('storageBucket')).toBe('edi-475fa.firebasestorage.app')
    expect(parsed.searchParams.get('messagingSenderId')).toBe('1234567890')
    expect(parsed.searchParams.get('appId')).toBe('1:1234567890:web:abcdef')
  })

  it('never leaks the service-account/VAPID — only the public web keys are present', () => {
    const url = buildFirebaseSwUrl('/firebase-messaging-sw.js', sampleConfig)
    const parsed = new URL(url, 'https://app.example.com')
    const keys = [...parsed.searchParams.keys()].sort()

    expect(keys).toEqual([
      'apiKey',
      'appId',
      'authDomain',
      'messagingSenderId',
      'projectId',
      'storageBucket',
    ])
  })
})

describe('getFirebaseWebConfig', () => {
  afterEach(() => {
    vi.unstubAllEnvs()
  })

  it('reads the firebase web config from VITE_ env vars', () => {
    vi.stubEnv('VITE_FIREBASE_API_KEY', 'test-api-key')
    vi.stubEnv('VITE_FIREBASE_AUTH_DOMAIN', 'edi-475fa.firebaseapp.com')
    vi.stubEnv('VITE_FIREBASE_PROJECT_ID', 'edi-475fa')
    vi.stubEnv('VITE_FIREBASE_STORAGE_BUCKET', 'edi-475fa.firebasestorage.app')
    vi.stubEnv('VITE_FIREBASE_MESSAGING_SENDER_ID', '1234567890')
    vi.stubEnv('VITE_FIREBASE_APP_ID', '1:1234567890:web:abcdef')
    vi.stubEnv('VITE_FIREBASE_MEASUREMENT_ID', 'G-TEST123')

    expect(getFirebaseWebConfig()).toEqual({
      apiKey: 'test-api-key',
      authDomain: 'edi-475fa.firebaseapp.com',
      projectId: 'edi-475fa',
      storageBucket: 'edi-475fa.firebasestorage.app',
      messagingSenderId: '1234567890',
      appId: '1:1234567890:web:abcdef',
      measurementId: 'G-TEST123',
    })
  })

  it('falls back to empty strings when env vars are unset (no hardcoded secrets)', () => {
    vi.stubEnv('VITE_FIREBASE_API_KEY', '')
    vi.stubEnv('VITE_FIREBASE_AUTH_DOMAIN', '')
    vi.stubEnv('VITE_FIREBASE_PROJECT_ID', '')
    vi.stubEnv('VITE_FIREBASE_STORAGE_BUCKET', '')
    vi.stubEnv('VITE_FIREBASE_MESSAGING_SENDER_ID', '')
    vi.stubEnv('VITE_FIREBASE_APP_ID', '')
    vi.stubEnv('VITE_FIREBASE_MEASUREMENT_ID', '')

    const config = getFirebaseWebConfig()
    expect(config.apiKey).toBe('')
    expect(config.projectId).toBe('')
    expect(config.measurementId).toBeUndefined()
  })
})
