/**
 * Tests para AgingBucketCard.
 * Verifica el renderizado por bucket y la expansión del detalle.
 */
import { AgingBucketCard } from '@/modules/package/components/AgingBucketCard'
import type { IAgingPackageEntry } from '@/modules/package/schema'
import { render, screen, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

const ENTRIES: IAgingPackageEntry[] = [
  {
    id: '1',
    unit_full_identifier: 'Casa 1',
    carrier: 'DHL',
    received_at: '2026-06-22T10:00:00Z',
    storage_location: 'Estante A-1',
    days_pending: 7,
  },
  {
    id: '2',
    unit_full_identifier: 'Casa 2',
    carrier: null,
    received_at: null,
    storage_location: null,
    days_pending: 5,
  },
]

describe('AgingBucketCard', () => {
  // ── Renderizado básico ───────────────────────────────────────────────────────

  it('muestra la etiqueta y el conteo', () => {
    render(
      <AgingBucketCard label="0 a 2 días" count={5} entries={[]} />,
    )
    expect(screen.getByText('0 a 2 días')).toBeInTheDocument()
    expect(screen.getByText('5')).toBeInTheDocument()
  })

  it('no muestra el botón "Ver detalle" cuando count=0', () => {
    render(<AgingBucketCard label="0 a 2 días" count={0} entries={[]} />)
    expect(screen.queryByText(/ver detalle/i)).not.toBeInTheDocument()
  })

  it('muestra el botón "Ver detalle" cuando count > 0', () => {
    render(<AgingBucketCard label="3 a 7 días" count={2} entries={ENTRIES} />)
    expect(screen.getByRole('button', { name: /ver detalle/i })).toBeInTheDocument()
  })

  // ── Expansión de detalle ────────────────────────────────────────────────────

  it('expande la lista al hacer click en "Ver detalle"', () => {
    render(<AgingBucketCard label="3 a 7 días" count={2} entries={ENTRIES} />)

    // Lista no visible al inicio
    expect(screen.queryByText('Casa 1')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /ver detalle/i }))

    // Lista visible tras click
    expect(screen.getByText('Casa 1')).toBeInTheDocument()
    expect(screen.getByText('Casa 2')).toBeInTheDocument()
  })

  it('colapsa la lista al hacer click de nuevo en "Ocultar detalle"', () => {
    render(<AgingBucketCard label="3 a 7 días" count={2} entries={ENTRIES} />)

    fireEvent.click(screen.getByRole('button', { name: /ver detalle/i }))
    expect(screen.getByText('Casa 1')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /ocultar detalle/i }))
    expect(screen.queryByText('Casa 1')).not.toBeInTheDocument()
  })

  // ── isOver7 ──────────────────────────────────────────────────────────────────

  it('muestra ícono de alerta cuando isOver7=true', () => {
    const { container } = render(
      <AgingBucketCard label="Más de 7 días" count={3} entries={[]} isOver7 />,
    )
    // El ícono tiene aria-hidden así que lo buscamos por presencia en el DOM
    const icon = container.querySelector('svg[aria-hidden]')
    expect(icon).toBeInTheDocument()
  })

  it('no muestra ícono de alerta cuando isOver7 no se pasa', () => {
    const { container } = render(
      <AgingBucketCard label="0 a 2 días" count={1} entries={[]} />,
    )
    const icon = container.querySelector('svg[aria-hidden]')
    expect(icon).not.toBeInTheDocument()
  })
})
