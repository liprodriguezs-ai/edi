import { useEffect } from 'react'

/**
 * Asks the browser to confirm closing or reloading the tab while `active`
 * (e.g. edits not saved yet). The browser shows its own generic message.
 */
export function useWarnBeforeUnload(active: boolean): void {
  useEffect(() => {
    if (!active) return
    const handleBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault()
    }
    window.addEventListener('beforeunload', handleBeforeUnload)
    return () => window.removeEventListener('beforeunload', handleBeforeUnload)
  }, [active])
}
