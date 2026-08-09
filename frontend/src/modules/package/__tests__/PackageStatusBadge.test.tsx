/**
 * Tests para PackageStatusBadge.
 * Verifica que el badge renderice la etiqueta en español correcta
 * y el tono de color correspondiente para cada clave del catálogo central.
 */
import { PackageStatusBadge } from '@/modules/package/components/PackageStatusBadge'
import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

describe('PackageStatusBadge', () => {
  // ── Labels en español ────────────────────────────────────────────────────────

  it.each([
    ['received', 'Recibido'],
    ['ready', 'Listo'],
    ['picked_up', 'Retirado'],
    ['returned', 'Devuelto'],
    ['incident', 'Incidente'],
  ])('key="%s" → muestra "%s"', (key, label) => {
    render(<PackageStatusBadge statusKey={key} statusName={null} statusColor={null} />)
    expect(screen.getByText(label)).toBeInTheDocument()
  })

  // ── statusName tiene precedencia sobre el key mapeado ──────────────────────

  it('statusName tiene precedencia sobre statusKey', () => {
    render(
      <PackageStatusBadge statusKey="ready" statusName="Personalizado" statusColor={null} />,
    )
    expect(screen.getByText('Personalizado')).toBeInTheDocument()
    expect(screen.queryByText('Listo')).not.toBeInTheDocument()
  })

  // ── Color hex produce inline style ──────────────────────────────────────────

  it('statusColor produce un span con estilo inline en lugar de StatusPill', () => {
    const { container } = render(
      <PackageStatusBadge statusKey={null} statusName="Personalizado" statusColor="#e74c3c" />,
    )
    const span = container.querySelector('span')
    // Con color hex, el componente renderiza un span con style inline
    expect(span).not.toBeNull()
    expect(span!.style.color).toBeTruthy()
  })

  // ── Fallback con key desconocido ─────────────────────────────────────────────

  it('key desconocido muestra el key como texto (fallback)', () => {
    render(
      <PackageStatusBadge statusKey="unknown_key" statusName={null} statusColor={null} />,
    )
    expect(screen.getByText('unknown_key')).toBeInTheDocument()
  })

  // ── Sin ningún dato muestra "—" ─────────────────────────────────────────────

  it('sin statusKey ni statusName muestra "—"', () => {
    render(<PackageStatusBadge statusKey={null} statusName={null} statusColor={null} />)
    expect(screen.getByText('—')).toBeInTheDocument()
  })
})
