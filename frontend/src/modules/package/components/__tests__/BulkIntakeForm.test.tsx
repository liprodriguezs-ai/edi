/**
 * Tests for BulkIntakeForm's per-row delinquency guard (design §8.3).
 *
 * Cubre: cada fila mantiene su unidad seleccionable, la nota SUTIL aparece
 * solo en la fila restringida, y el submit del lote se bloquea mientras
 * CUALQUIER fila tenga una unidad restringida.
 */
import { BulkIntakeForm } from '@/modules/package/components/BulkIntakeForm'
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

const mockBatch = vi.mocked(PackageResource.batch)
const mockUseOptions = vi.mocked(usePackageCatalogOptions)

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('BulkIntakeForm — delinquency guard', () => {
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

  it('shows a subtle note on the restricted row and blocks the batch submit while it remains', () => {
    render(<BulkIntakeForm onCreated={vi.fn()} onClose={vi.fn()} />, { wrapper: makeWrapper() })

    const submitButton = screen.getByRole('button', { name: /Registrar 1 paquete/ })

    // Unit A: no note, submit stays enabled.
    fireEvent.click(screen.getByText('Casa 1'))
    expect(screen.queryByText('Esta unidad no puede recibir paquetes.')).not.toBeInTheDocument()
    expect(submitButton).not.toBeDisabled()

    // Unit B: note appears on that row, submit is blocked for the WHOLE batch.
    fireEvent.click(screen.getByText('Casa 2'))
    expect(screen.getByText('Esta unidad no puede recibir paquetes.')).toBeInTheDocument()
    expect(submitButton).toBeDisabled()

    fireEvent.click(submitButton)
    expect(mockBatch).not.toHaveBeenCalled()

    // Switching back to a good-standing unit lifts the block for that row.
    fireEvent.click(screen.getByText('Casa 1'))
    expect(screen.queryByText('Esta unidad no puede recibir paquetes.')).not.toBeInTheDocument()
    expect(submitButton).not.toBeDisabled()
  })
})
