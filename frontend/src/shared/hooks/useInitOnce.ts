import { useEffect, useRef } from 'react'

/**
 * Runs a callback exactly once per component instance,
 * resilient to React 18 StrictMode's double-mount cycle.
 *
 * In StrictMode, React simulates unmount/remount but the
 * ref value persists across that cycle, preventing the
 * duplicate execution. On a real remount (navigation away
 * and back), a fresh ref is created so the callback fires
 * again as expected.
 */
export const useInitOnce = (callback: () => void | Promise<void>, _key: string) => {
  const hasRun = useRef(false)
  const callbackRef = useRef(callback)

  useEffect(() => {
    callbackRef.current = callback
  })

  useEffect(() => {
    if (hasRun.current) return
    hasRun.current = true
    callbackRef.current()
  }, [])
}
