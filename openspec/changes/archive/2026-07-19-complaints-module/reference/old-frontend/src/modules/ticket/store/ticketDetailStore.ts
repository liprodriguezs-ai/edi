import { create } from 'zustand'

interface TicketDetailState {
  /** Se incrementa cada vez que hay un cambio en el ticket (mensaje, status, prioridad, asignación, archivo) */
  version: number
  /** Incrementa version — todos los componentes que observen `version` se actualizan */
  bump: () => void
}

export const useTicketDetailStore = create<TicketDetailState>((set) => ({
  version: 0,
  bump: () => set((s) => ({ version: s.version + 1 })),
}))
