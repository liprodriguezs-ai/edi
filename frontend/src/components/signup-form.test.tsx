/**
 * Tests for SignupForm's post-registration routing (D5).
 *
 * Registration no longer grants any local access: an ACCOUNT_OWNER must ALWAYS
 * land on /select-plan, with or without a `?plan=` in the URL — the old
 * plan-key precondition used to send a bare registration straight to `/`,
 * where the owner would be stuck with no plan and no card.
 *
 * Strategy: mock useSession().register and render SignupForm inside routes for
 * /select-plan and / so we can assert which one the app actually navigated to.
 */
import { SignupForm } from '@/components/signup-form'
import { ERoleUserSlug } from '@/shared/interfaces/Entity'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockRegister = vi.fn()

vi.mock('@/shared/auth/useSession', () => ({
  useSession: () => ({ register: mockRegister }),
}))

vi.mock('@/shared/hooks/useToast', () => ({
  toast: { error: vi.fn(), success: vi.fn(), info: vi.fn() },
}))

/**
 * Stub de destino que EXPONE la query string, para poder afirmar exactamente qué
 * se reenvía en `?plan=` (y no sólo que se llegó a la pantalla).
 */
function SelectPlanStub() {
  const { search } = useLocation()
  return (
    <div>
      <span>Selecciona tu plan</span>
      <span data-testid='search'>{search}</span>
    </div>
  )
}

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path='/register' element={<SignupForm />} />
        <Route path='/select-plan' element={<SelectPlanStub />} />
        <Route path='/' element={<div>Home</div>} />
      </Routes>
    </MemoryRouter>
  )
}

function fillAndSubmit() {
  fireEvent.change(screen.getByLabelText(/nombre completo/i), { target: { value: 'Ana Pérez' } })
  fireEvent.change(screen.getByLabelText(/nombre del condominio/i), {
    target: { value: 'Las Palmas' },
  })
  fireEvent.change(screen.getByLabelText(/correo electrónico/i), {
    target: { value: 'ana@test.mx' },
  })
  fireEvent.change(screen.getByLabelText('Contraseña'), { target: { value: 'Secret123' } })
  fireEvent.click(screen.getByRole('button', { name: /continuar/i }))
}

describe('SignupForm — post-registration routing (D5)', () => {
  beforeEach(() => {
    mockRegister.mockReset()
  })

  it('routes an account owner to /select-plan even without ?plan= in the URL', async () => {
    mockRegister.mockResolvedValue({ roles: [ERoleUserSlug.ACCOUNT_OWNER] })
    renderAt('/register')

    fillAndSubmit()

    expect(await screen.findByText('Selecciona tu plan')).toBeInTheDocument()
  })

  it('routes an account owner to /select-plan and keeps a valid ?plan= key', async () => {
    mockRegister.mockResolvedValue({ roles: [ERoleUserSlug.ACCOUNT_OWNER] })
    renderAt('/register?plan=esencial')

    fillAndSubmit()

    expect(await screen.findByText('Selecciona tu plan')).toBeInTheDocument()
  })

  it('never routes an account owner to / (the old dead-end for a bare registration)', async () => {
    mockRegister.mockResolvedValue({ roles: [ERoleUserSlug.ACCOUNT_OWNER] })
    renderAt('/register')

    fillAndSubmit()

    await screen.findByText('Selecciona tu plan')
    expect(screen.queryByText('Home')).not.toBeInTheDocument()
  })

  it('routes a non-owner (invited member) to home, not /select-plan', async () => {
    mockRegister.mockResolvedValue({ roles: [ERoleUserSlug.RESIDENT] })
    renderAt('/register')

    fillAndSubmit()

    expect(await screen.findByText('Home')).toBeInTheDocument()
  })

  // El catálogo del backend es la ÚNICA autoridad sobre qué keys existen. El
  // frontend ya no guarda una lista para pre-filtrar: reenvía la key tal cual y
  // /select-plan la resuelve contra el catálogo (cae al primer plan si no aparece).
  // Antes, una key legítima de un plan NUEVO se descartaba en silencio aquí hasta
  // que alguien se acordara de editar la constante local.
  it('forwards a plan key the frontend has never heard of (the catalog decides, not a local list)', async () => {
    mockRegister.mockResolvedValue({ roles: [ERoleUserSlug.ACCOUNT_OWNER] })
    renderAt('/register?plan=plan_nuevo_del_backend')

    fillAndSubmit()

    await screen.findByText('Selecciona tu plan')
    expect(screen.getByTestId('search')).toHaveTextContent('?plan=plan_nuevo_del_backend')
  })

  // Sin lista blanca, la key es texto arbitrario del usuario: debe ir codificada o
  // un `&` inyectaría parámetros extra en nuestra propia URL de destino.
  it('encodes the plan key so it cannot inject extra query parameters', async () => {
    mockRegister.mockResolvedValue({ roles: [ERoleUserSlug.ACCOUNT_OWNER] })
    renderAt('/register?plan=' + encodeURIComponent('basico&admin=1'))

    fillAndSubmit()

    await screen.findByText('Selecciona tu plan')
    const search = screen.getByTestId('search').textContent ?? ''
    expect(search).toContain('plan=basico%26admin%3D1')
    expect(new URLSearchParams(search).get('admin')).toBeNull()
  })
})
