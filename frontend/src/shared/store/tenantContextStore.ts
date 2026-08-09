import { create } from 'zustand'

interface TenantContextState {
  tenantId: string | null
  tenantName: string | null
  setTenant: (id: string, name: string) => void
  clearTenant: () => void
}

export const useTenantContextStore = create<TenantContextState>((set) => ({
  tenantId: null,
  tenantName: null,
  setTenant: (id, name) => set({ tenantId: id, tenantName: name }),
  clearTenant: () => set({ tenantId: null, tenantName: null }),
}))
