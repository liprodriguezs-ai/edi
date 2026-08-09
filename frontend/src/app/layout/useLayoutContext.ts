import type { DashboardLayoutContext } from '@/app/layout/DashboardLayout'
import { createContext, useContext } from 'react'

const LayoutContext = createContext<DashboardLayoutContext | undefined>(undefined)

export function useLayoutContext(): DashboardLayoutContext {
  const ctx = useContext(LayoutContext)
  if (ctx === undefined) {
    return {
      setFullWidth: () => {},
    }
  }
  return ctx
}

export { LayoutContext }
