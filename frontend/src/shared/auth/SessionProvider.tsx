import { sessionStore } from '@/shared/auth/sessionStore'
import { useSession } from '@/shared/auth/useSession'
import type { JSX, PropsWithChildren } from 'react'
import { useEffect, useRef, useState } from 'react'

export const SessionProvider = ({ children }: PropsWithChildren): JSX.Element => {
  const { restoreSession, validateSession } = useSession()
  const hasInitialized = useRef(false)
  const [ready, setReady] = useState(false)

  useEffect(() => {
    if (hasInitialized.current) return
    hasInitialized.current = true

    // Bootstrap de sesión al montar la app. Se envuelve en una función interna
    // (patrón "fetching data in an Effect" de React) para que TODAS las salidas
    // — con o sin sesión previa — marquen "ready" desde un callback en vez de
    // sincrónicamente en el cuerpo del efecto (evita el cascading render).
    const bootstrap = async () => {
      restoreSession()

      const state = sessionStore.getState()
      if (state.token || state.profile) {
        await validateSession().catch(() => {})
      }

      document.documentElement.classList.add('app-ready')
      setReady(true)
    }

    void bootstrap()
  }, [restoreSession, validateSession])

  if (!ready) return <></>

  return <>{children}</>
}
