/**
 * Tests for PackageForm's (single intake) delinquency guard (design §8.3).
 *
 * Cubre: la unidad sigue siendo seleccionable (nada oculto/disabled en el
 * dropdown), aparece una nota SUTIL bajo el campo cuando la unidad elegida
 * está restringida, y el submit se bloquea solo mientras esté seleccionada.
 */
import { PackageForm } from '@/modules/package/PackageForm'
import { PackageResource } from '@/modules/package/services/PackageResource'
import { usePackageCatalogOptions } from '@/modules/package/hooks/usePackageCatalogOptions'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/package/services/PackageResource', () => ({
  PackageResource: { create: vi.fn(), batch: vi.fn() },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('@/modules/package/hooks/usePackageCatalogOptions')

// Stub the shadcn/Radix Select so tests can pick an option without driving
// Radix's portal/pointer-capture internals (unexercised elsewhere in this repo).
vi.mock('@/components/ui/select', async () => {
  const React = await import('react')
  const SelectCtx = React.createContext<{ onValueChange?: (_v: string) => void }>({})
  return {
    Select: ({
      children,
      onValueChange,
    }: {
      children: ReactNode
      onValueChange: (_v: string) => void
    }) => <SelectCtx.Provider value={{ onValueChange }}>{children}</SelectCtx.Provider>,
    SelectTrigger: ({ children, ...rest }: { children: ReactNode }) => (
      <div {...rest}>{children}</div>
    ),
    SelectValue: () => null,
    SelectContent: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    SelectItem: ({ children, value }: { children: ReactNode; value: string }) => {
      const { onValueChange } = React.useContext(SelectCtx)
      return (
        <button type='button' onClick={() => onValueChange?.(value)}>
          {children}
        </button>
      )
    },
  }
})

const mockCreate = vi.mocked(PackageResource.create)
const mockUseOptions = vi.mocked(usePackageCatalogOptions)

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('PackageForm (single intake) — delinquency guard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockUseOptions.mockReturnValue({
      unitOptions: [
        { value: 'unit-a', label: 'Casa 1' },
        { value: 'unit-b', label: 'Casa 2' },
      ],
      carrierOptions: [],
      typeOptions: [],
      enableUnits: vi.fn(),
      enableCarriers: vi.fn(),
      enableTypes: vi.fn(),
      loadingUnits: false,
      restrictedUnitIds: new Set(['unit-b']),
    })
  })

  it('keeps the unit dropdown fully populated — nothing hidden for a restricted unit', () => {
    render(<PackageForm isOpen onClose={vi.fn()} onCreated={vi.fn()} />, {
      wrapper: makeWrapper(),
    })

    expect(screen.getByText('Casa 1')).toBeInTheDocument()
    expect(screen.getByText('Casa 2')).toBeInTheDocument()
  })

  it('shows a subtle note and blocks submit only when the restricted unit is selected', () => {
    render(<PackageForm isOpen onClose={vi.fn()} onCreated={vi.fn()} />, {
      wrapper: makeWrapper(),
    })

    // Unit A: no note, submit stays enabled.
    fireEvent.click(screen.getByText('Casa 1'))
    expect(screen.queryByText('Esta unidad no puede recibir paquetes.')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Registrar' })).not.toBeDisabled()

    // Unit B: subtle note appears, submit is blocked.
    fireEvent.click(screen.getByText('Casa 2'))
    expect(screen.getByText('Esta unidad no puede recibir paquetes.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Registrar' })).toBeDisabled()

    fireEvent.click(screen.getByRole('button', { name: 'Registrar' }))
    expect(mockCreate).not.toHaveBeenCalled()
  })
})
