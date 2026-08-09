import { EntityNotFound } from '@/shared/components/EntityNotFound'
import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

function renderWithRouter(ui: React.ReactElement) {
  return render(<MemoryRouter>{ui}</MemoryRouter>)
}

describe('EntityNotFound', () => {
  it('renders the notFound variant defaults in Spanish', () => {
    renderWithRouter(<EntityNotFound />)

    expect(screen.getByText('No se encontró el registro')).toBeInTheDocument()
    expect(screen.getByText('El registro no existe o no tienes acceso a él.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Reintentar' })).not.toBeInTheDocument()
  })

  it('renders the error variant defaults with a retry button', () => {
    const onRetry = vi.fn()
    renderWithRouter(<EntityNotFound variant='error' onRetry={onRetry} />)

    expect(screen.getByText('No se pudo cargar la información')).toBeInTheDocument()
    const retry = screen.getByRole('button', { name: 'Reintentar' })
    fireEvent.click(retry)
    expect(onRetry).toHaveBeenCalledTimes(1)
  })

  it('does not render the retry button for the notFound variant', () => {
    renderWithRouter(<EntityNotFound onRetry={vi.fn()} />)

    expect(screen.queryByRole('button', { name: 'Reintentar' })).not.toBeInTheDocument()
  })

  it('overrides title and description', () => {
    renderWithRouter(<EntityNotFound title='Proyecto no encontrado' description='No existe.' />)

    expect(screen.getByText('Proyecto no encontrado')).toBeInTheDocument()
    expect(screen.getByText('No existe.')).toBeInTheDocument()
  })

  it('renders a back link when backTo is provided', () => {
    renderWithRouter(<EntityNotFound backTo='/unit' backLabel='Volver a unidades' />)

    const link = screen.getByRole('link', { name: /volver a unidades/i })
    expect(link).toHaveAttribute('href', '/unit')
  })

  it('renders a back button when onBack is provided without backTo', () => {
    const onBack = vi.fn()
    renderWithRouter(<EntityNotFound onBack={onBack} />)

    fireEvent.click(screen.getByRole('button', { name: /volver/i }))
    expect(onBack).toHaveBeenCalledTimes(1)
  })
})
