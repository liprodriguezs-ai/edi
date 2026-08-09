/**
 * Tests del buscador de personas.
 *
 * Cubren el bug reportado en Mesa Directiva: se tecleaba el nombre sin elegirlo
 * del menú, el texto quedaba a la vista y el formulario marcaba el campo vacío.
 */
import { UserCombobox } from '@/modules/unit/components/UserCombobox'
import type { IUser } from '@/modules/user/schema'
import { UserResource } from '@/modules/user/services/UserResource'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/modules/user/services/UserResource', () => ({
  UserResource: { searchByTerm: vi.fn() },
}))

const CATALINA: IUser = {
  id: 'user-1',
  name: 'Catalina Gómez',
  full_name: 'Catalina Gómez',
  email: 'catalina@example.com',
  phone: '5512345678',
}

const VERONICA: IUser = {
  id: 'user-2',
  name: 'Verónica Valdez',
  full_name: 'Verónica Valdez',
  email: 'veronica@example.com',
}

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  }
}

/** Enfoca el buscador y espera a que el menú traiga resultados. */
async function openAndWaitForResults() {
  const input = screen.getByRole('combobox')
  fireEvent.focus(input)
  await screen.findByText('Catalina Gómez')
  return input
}

describe('UserCombobox', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(UserResource.searchByTerm).mockResolvedValue([CATALINA, VERONICA])
  })

  it('borra lo tecleado al salir sin elegir, para que ningún nombre aparente estar guardado', async () => {
    const onSelect = vi.fn()
    render(<UserCombobox value='' onSelect={onSelect} />, { wrapper: makeWrapper() })

    const input = await openAndWaitForResults()
    fireEvent.change(input, { target: { value: 'Catalina' } })
    expect(input).toHaveValue('Catalina')

    fireEvent.blur(input)

    expect(input).toHaveValue('')
    expect(onSelect).not.toHaveBeenCalled()
  })

  it('elegir del menú avisa al formulario y cambia el campo por una ficha sin texto editable', async () => {
    const onSelect = vi.fn()
    const { rerender } = render(<UserCombobox value='' onSelect={onSelect} />, {
      wrapper: makeWrapper(),
    })

    await openAndWaitForResults()
    fireEvent.click(screen.getByText('Catalina Gómez'))

    expect(onSelect).toHaveBeenCalledWith(CATALINA)

    // El formulario propaga el id elegido: el buscador pasa a modo ficha.
    rerender(<UserCombobox value={CATALINA.id} onSelect={onSelect} />)

    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
    expect(screen.getByText('Catalina Gómez')).toBeInTheDocument()
  })

  it('Enter elige el resultado resaltado en vez de enviar el formulario', async () => {
    const onSelect = vi.fn()
    render(<UserCombobox value='' onSelect={onSelect} />, { wrapper: makeWrapper() })

    const input = await openAndWaitForResults()
    // fireEvent devuelve false cuando el handler llamó preventDefault: eso es lo
    // que impide el submit implícito del formulario contenedor.
    const notPrevented = fireEvent.keyDown(input, { key: 'Enter' })

    expect(notPrevented).toBe(false)
    expect(onSelect).toHaveBeenCalledWith(CATALINA)
  })

  it('las flechas mueven el resaltado y Enter elige a esa persona', async () => {
    const onSelect = vi.fn()
    render(<UserCombobox value='' onSelect={onSelect} />, { wrapper: makeWrapper() })

    const input = await openAndWaitForResults()
    fireEvent.keyDown(input, { key: 'ArrowDown' })
    fireEvent.keyDown(input, { key: 'Enter' })

    expect(onSelect).toHaveBeenCalledWith(VERONICA)
  })

  it('la ficha permite quitar a la persona cuando el formulario ofrece onClear', async () => {
    const onClear = vi.fn()
    render(
      <UserCombobox
        value={CATALINA.id}
        initialLabel='Catalina Gómez'
        onSelect={vi.fn()}
        onClear={onClear}
      />,
      { wrapper: makeWrapper() }
    )

    fireEvent.click(screen.getByRole('button', { name: 'Quitar a Catalina Gómez' }))

    expect(onClear).toHaveBeenCalledTimes(1)
  })

  it('no busca nada hasta que el usuario abre el campo (carga lazy)', async () => {
    render(<UserCombobox value='' onSelect={vi.fn()} />, { wrapper: makeWrapper() })

    expect(UserResource.searchByTerm).not.toHaveBeenCalled()

    fireEvent.focus(screen.getByRole('combobox'))

    await waitFor(() => expect(UserResource.searchByTerm).toHaveBeenCalled())
  })
})
