/**
 * Tests para ComplaintForm.
 *
 * (1) Renderiza el diálogo con los campos requeridos.
 * (2) Al enviar datos válidos, llama a ComplaintResource.create con el
 *     payload esperado (sin FormData cuando no hay evidencia adjunta).
 * (3) Bloquea el envío y muestra errores de validación con campos vacíos.
 */
import { ComplaintForm } from '@/modules/complaint/ComplaintForm'
import { ComplaintResource } from '@/modules/complaint/services/ComplaintResource'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/complaint/services/ComplaintResource', () => ({
  ComplaintResource: { create: vi.fn() },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

// Catalogs keyed by the single CatalogType passed to each useLazyCatalogs call —
// mirrors ComplaintForm's 3 independent useLazyCatalogs invocations (category/
// priority/unit), each keyed by exactly one CatalogType.
vi.mock('@/shared/hooks/useCatalogs', () => ({
  useLazyCatalogs: (typesMap: Record<string, unknown>) => {
    const key = Object.keys(typesMap)[0]
    const catalogsByType: Record<string, unknown[]> = {
      complaint_category: [{ id: 'cat-1', name: 'Convivencia entre vecinos' }],
      complaint_priority: [{ id: 'pri-1', name: 'Media' }],
      unit: [],
    }
    return {
      catalogs: { [key]: catalogsByType[key] ?? [] },
      enable: vi.fn(),
      isLoading: false,
    }
  },
  CatalogType: {
    COMPLAINT_CATEGORY: 'complaint_category',
    COMPLAINT_PRIORITY: 'complaint_priority',
    UNIT: 'unit',
  },
}))

// Stub the shadcn/Radix Select so tests can pick an option without driving
// Radix's portal/pointer-capture internals (Package precedent).
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

// SearchSelect (denounced unit, optional) — not exercised by these tests.
vi.mock('@/shared/components/SearchSelect', () => ({
  SearchSelect: () => null,
}))

const mockCreate = vi.mocked(ComplaintResource.create)

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('ComplaintForm', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the dialog with the required fields when open', () => {
    render(<ComplaintForm isOpen onClose={vi.fn()} onCreated={vi.fn()} />, {
      wrapper: makeWrapper(),
    })

    expect(screen.getByRole('heading', { name: 'Registrar incidencia' })).toBeInTheDocument()
    expect(screen.getByLabelText('Título')).toBeInTheDocument()
    expect(screen.getByText('Convivencia entre vecinos')).toBeInTheDocument()
    expect(screen.getByText('Media')).toBeInTheDocument()
  })

  it('submits valid data as a plain object (no evidence attached)', async () => {
    mockCreate.mockResolvedValueOnce({
      id: 'complaint-1',
      case_number: 'D-2026-000001',
      title: 'Ruido excesivo',
      complaint_category_id: 'cat-1',
      complaint_priority_id: 'pri-1',
      complaint_status_id: 'status-1',
      reporter_user_id: 'user-1',
      against_description: 'Vecino con música alta',
    } as never)

    const onCreated = vi.fn()
    render(<ComplaintForm isOpen onClose={vi.fn()} onCreated={onCreated} />, {
      wrapper: makeWrapper(),
    })

    fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Ruido excesivo' } })
    fireEvent.click(screen.getByText('Convivencia entre vecinos'))
    fireEvent.click(screen.getByText('Media'))
    fireEvent.change(screen.getByLabelText('Descripción'), {
      target: { value: 'Vecino con música alta después de medianoche' },
    })

    fireEvent.click(screen.getByRole('button', { name: 'Registrar incidencia' }))

    await waitFor(() => expect(mockCreate).toHaveBeenCalledTimes(1))

    expect(mockCreate).toHaveBeenCalledWith({
      title: 'Ruido excesivo',
      complaint_category_id: 'cat-1',
      complaint_priority_id: 'pri-1',
      against_unit_id: undefined,
      against_description: 'Vecino con música alta después de medianoche',
    })
    await waitFor(() => expect(onCreated).toHaveBeenCalled())
  })

  it('blocks submit and shows validation errors with empty fields', async () => {
    render(<ComplaintForm isOpen onClose={vi.fn()} onCreated={vi.fn()} />, {
      wrapper: makeWrapper(),
    })

    fireEvent.click(screen.getByRole('button', { name: 'Registrar incidencia' }))

    await waitFor(() => expect(screen.getByText('El título es requerido')).toBeInTheDocument())
    expect(screen.getByText('La descripción es requerida')).toBeInTheDocument()
    expect(mockCreate).not.toHaveBeenCalled()
  })
})
