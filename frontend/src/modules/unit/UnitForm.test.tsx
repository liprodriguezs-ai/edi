/**
 * trial-usage-quotas (REQ-13): the plan-upgrade dialog must open on the
 * machine code the backend ACTUALLY emits for a band rejection
 * (`UNIT_LIMIT_EXCEEDED`), not the stale `'unit_limit_reached'` string that
 * never matched. Scoped narrowly to this error-code branch — not full
 * `UnitForm` coverage (no existing test file covered this component before).
 */
import { UnitForm } from '@/modules/unit/UnitForm'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { ApiError } from '@/shared/api/apiClient'
import { BUSINESS_CODES } from '@/shared/api/businessCodes'
import { CrudMode } from '@/shared/enums/CrudMode'
import { toast } from '@/shared/hooks/useToast'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** Node's process.on/removeListener — @types/node isn't in tsconfig.app.json's `types`. */
declare const process: {
  on: (_event: 'unhandledRejection', _listener: (_reason: unknown) => void) => void
  removeListener: (_event: 'unhandledRejection', _listener: (_reason: unknown) => void) => void
}

vi.mock('@/modules/unit/services/UnitResource', () => ({
  UnitResource: { create: vi.fn(), update: vi.fn(), remove: vi.fn() },
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

vi.mock('@/shared/hooks/useCatalogs', async () => {
  const actual =
    await vi.importActual<typeof import('@/shared/hooks/useCatalogs')>(
      '@/shared/hooks/useCatalogs'
    )
  return {
    ...actual,
    useCatalogs: () => ({
      catalogs: {
        [actual.CatalogType.UNIT_TYPE]: [
          { id: 'type-casa', name: 'Casa', requires_floor: false },
        ],
        [actual.CatalogType.UNIT_STATUS]: [{ id: 'status-activa', name: 'Activa' }],
        [actual.CatalogType.TOWER]: [],
        [actual.CatalogType.SECTION]: [],
      },
      isLoading: false,
    }),
  }
})

// SearchSelect is react-select-based; stub it as a native <select> keyed by
// `id` so the required "Estado" field can be filled without driving
// react-select's internals (unexercised by this narrow test).
vi.mock('@/shared/components/SearchSelect', () => ({
  SearchSelect: ({
    id,
    value,
    onChange,
    options,
    placeholder,
  }: {
    id?: string
    value?: string | string[] | null
    onChange: (_v: string | null) => void
    options?: Array<{ id: string; name?: string }>
    placeholder?: string
  }) => (
    <select
      data-testid={id}
      value={(value as string) ?? ''}
      onChange={(e) => onChange(e.target.value || null)}
    >
      <option value=''>{placeholder}</option>
      {options?.map((o) => (
        <option key={o.id} value={o.id}>
          {o.name}
        </option>
      ))}
    </select>
  ),
}))

const mockCreate = vi.mocked(UnitResource.create)

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

describe('UnitForm — band rejection error-code branch (REQ-13)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('opens the upgrade dialog on UNIT_LIMIT_EXCEEDED and does NOT show the generic error toast', async () => {
    // UnitForm.tsx's `doSubmit` is invoked as `void doSubmit(e)` — a
    // pre-existing, codebase-wide pattern (every module's Form.tsx calls
    // `crud.submit` the same way). useBaseCrud's `onSave` handles the error
    // (toast/onError callback) but ALSO re-throws it, and nothing downstream
    // attaches a `.catch()` to the discarded `doSubmit` promise, so a rejected
    // submit always surfaces as an unhandled rejection at the process level —
    // harmless in the browser (React discards the handler's return value
    // either way), but Vitest's Node runtime flags it as a test error. Not a
    // regression of this change; scoped suppression only for this assertion.
    const swallowUnhandledRejection = (reason: unknown) => {
      if (reason instanceof ApiError && reason.payload?.code === BUSINESS_CODES.UNIT_LIMIT_EXCEEDED) return
      throw reason
    }
    process.on('unhandledRejection', swallowUnhandledRejection)

    mockCreate.mockRejectedValue(
      new ApiError('Tu plan Básico permite hasta 25 unidades activas y ya tienes 25.', 422, {
        code: BUSINESS_CODES.UNIT_LIMIT_EXCEEDED,
        unit_limit: 25,
      })
    )
    const onUnitLimitReached = vi.fn()

    render(<UnitForm mode={CrudMode.CREATE} onUnitLimitReached={onUnitLimitReached} />, {
      wrapper: makeWrapper(),
    })

    fireEvent.click(screen.getByRole('radio', { name: 'Casa' }))
    fireEvent.change(screen.getByLabelText('Número'), { target: { value: '101' } })
    fireEvent.change(screen.getByTestId('unit-status'), { target: { value: 'status-activa' } })

    fireEvent.click(screen.getByRole('button', { name: 'Guardar unidad' }))

    await waitFor(() => expect(mockCreate).toHaveBeenCalled())
    await waitFor(() => expect(onUnitLimitReached).toHaveBeenCalledWith(25))

    expect(toast.error).not.toHaveBeenCalled()

    process.removeListener('unhandledRejection', swallowUnhandledRejection)
  })
})
