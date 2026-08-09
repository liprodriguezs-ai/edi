import { create } from 'zustand'

interface CommandPaletteState {
  /** Si la paleta de comandos (⌘K) está abierta. */
  open: boolean
  setOpen: (open: boolean) => void
  toggle: () => void
}

/**
 * Estado compartido de la paleta de comandos (⌘K). Vive en un store para que
 * tanto el header como el sidebar puedan abrirla sin duplicar el componente
 * ni el atajo de teclado.
 */
export const useCommandPalette = create<CommandPaletteState>((set) => ({
  open: false,
  setOpen: (open) => set({ open }),
  toggle: () => set((state) => ({ open: !state.open })),
}))
