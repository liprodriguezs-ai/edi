/**
 * Characterization tests for ValidateStep.
 *
 * Asserts:
 * - "Continuar" disabled when blocked > 0
 * - "Continuar" enabled when blocked === 0
 * - Error rows rendered with red class
 * - Warning rows rendered with amber class
 * - "Subir otro archivo" always returns to Step 1 (onBack)
 */
import { ValidateStep } from '@/modules/import/components/ValidateStep'
import type { IValidateResponse } from '@/modules/import/schema'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

function buildResponse(overrides: Partial<IValidateResponse> = {}): IValidateResponse {
  return {
    total: 10,
    units_to_create: 8,
    residents_to_create: 8,
    payments_to_register: 10,
    blocked: 0,
    rows: [],
    ...overrides,
  }
}

describe('ValidateStep', () => {
  const onContinue = vi.fn()
  const onBack = vi.fn()

  beforeEach(() => {
    vi.clearAllMocks()
  })

  // ── Continue button state ─────────────────────────────────────────────────

  it('disables Continuar when blocked > 0', () => {
    render(
      <ValidateStep
        response={buildResponse({ blocked: 2 })}
        onContinue={onContinue}
        onBack={onBack}
      />
    )

    const btn = screen.getByTestId('continue-button')
    expect(btn).toBeDisabled()
  })

  it('enables Continuar when blocked === 0', () => {
    render(
      <ValidateStep
        response={buildResponse({ blocked: 0 })}
        onContinue={onContinue}
        onBack={onBack}
      />
    )

    const btn = screen.getByTestId('continue-button')
    expect(btn).not.toBeDisabled()
  })

  it('calls onContinue when Continuar is clicked and not blocked', async () => {
    render(
      <ValidateStep
        response={buildResponse({ blocked: 0 })}
        onContinue={onContinue}
        onBack={onBack}
      />
    )

    await act(async () => {
      fireEvent.click(screen.getByTestId('continue-button'))
    })
    expect(onContinue).toHaveBeenCalledOnce()
  })

  // ── Back / re-upload ──────────────────────────────────────────────────────

  it('calls onBack when "Subir otro archivo" is clicked, even when blocked', async () => {
    render(
      <ValidateStep
        response={buildResponse({ blocked: 3 })}
        onContinue={onContinue}
        onBack={onBack}
      />
    )

    await act(async () => {
      fireEvent.click(screen.getByTestId('back-button'))
    })
    expect(onBack).toHaveBeenCalledOnce()
  })

  // ── Issue row rendering ───────────────────────────────────────────────────

  it('renders error rows with the error message in the table', () => {
    const response = buildResponse({
      blocked: 1,
      rows: [{ row: 5, severity: 'error', column: 'monto', message: 'El monto no es un número.' }],
    })

    render(<ValidateStep response={response} onContinue={onContinue} onBack={onBack} />)

    // The error table section header
    expect(screen.getByText('Errores (filas bloqueadas)')).toBeInTheDocument()
    // Error message appears in the table
    expect(screen.getByText('El monto no es un número.')).toBeInTheDocument()
    // Row number shows
    expect(screen.getByText('5')).toBeInTheDocument()
  })

  it('renders warning rows with the warning message in the table', () => {
    const response = buildResponse({
      blocked: 0,
      rows: [
        {
          row: 9,
          severity: 'warning',
          column: 'tipo_unidad',
          message: "El tipo 'PH' no está registrado.",
        },
      ],
    })

    render(<ValidateStep response={response} onContinue={onContinue} onBack={onBack} />)

    // Warning table section header
    expect(screen.getByText('Advertencias (filas incluidas con aviso)')).toBeInTheDocument()
    expect(screen.getByText("El tipo 'PH' no está registrado.")).toBeInTheDocument()
  })

  it('shows both error and warning sections when both are present', () => {
    const response = buildResponse({
      blocked: 1,
      rows: [
        { row: 3, severity: 'error', column: 'numero', message: 'El número es obligatorio.' },
        {
          row: 7,
          severity: 'warning',
          column: 'cuenta_destino',
          message: "Cuenta 'Caja 2' no existe.",
        },
      ],
    })

    render(<ValidateStep response={response} onContinue={onContinue} onBack={onBack} />)

    expect(screen.getByText('Errores (filas bloqueadas)')).toBeInTheDocument()
    expect(screen.getByText('Advertencias (filas incluidas con aviso)')).toBeInTheDocument()
  })

  it('shows success message when there are no errors and no warnings', () => {
    render(
      <ValidateStep
        response={buildResponse({ blocked: 0, rows: [] })}
        onContinue={onContinue}
        onBack={onBack}
      />
    )

    expect(screen.getByText(/sin errores/i)).toBeInTheDocument()
  })

  // ── Grouped issue "Fila" cell (REQ-04) ────────────────────────────────────

  it('renders "N filas" for a grouped issue instead of a single row number', () => {
    const response = buildResponse({
      blocked: 0,
      rows: [
        {
          row: 4,
          severity: 'warning',
          column: 'cuenta_destino',
          message: "La cuenta 'Caja 2' no existe — se creará automáticamente al ejecutar.",
          general: true,
          count: 1000,
        },
      ],
    })

    render(<ValidateStep response={response} onContinue={onContinue} onBack={onBack} />)

    expect(screen.getByText('1,000 filas')).toBeInTheDocument()
  })

  it('renders "Archivo" for a file-level issue (row 0), not the literal 0', () => {
    const response = buildResponse({
      blocked: 1,
      rows: [
        {
          row: 0,
          severity: 'error',
          column: 'unidades',
          general: true,
          message:
            'Tu plan Básico permite hasta 25 unidades activas y ya tienes 6; este archivo crearía 30 unidades nuevas. Reduce el archivo o cambia al plan Inicial.',
        },
      ],
    })

    render(<ValidateStep response={response} onContinue={onContinue} onBack={onBack} />)

    expect(screen.getByText('Archivo')).toBeInTheDocument()
    expect(screen.queryByText('0')).not.toBeInTheDocument()
  })

  it('renders the plain row number for an ungrouped issue', () => {
    const response = buildResponse({
      blocked: 0,
      rows: [
        {
          row: 9,
          severity: 'warning',
          column: 'tipo_unidad',
          message: "El tipo 'PH' no está registrado.",
        },
      ],
    })

    render(<ValidateStep response={response} onContinue={onContinue} onBack={onBack} />)

    expect(screen.getByText('9')).toBeInTheDocument()
  })

  // ── Truncation notice (D7, REQ-11) ────────────────────────────────────────

  it('renders the truncation notice when response.truncated is true', () => {
    const response = buildResponse({
      truncated: true,
      row_cap: 1000,
      preview_message:
        'Se validaron las primeras 1000 filas del archivo. Importá el archivo completo para procesar el resto.',
    })

    render(<ValidateStep response={response} onContinue={onContinue} onBack={onBack} />)

    expect(
      screen.getByText(
        'Se validaron las primeras 1000 filas del archivo. Importá el archivo completo para procesar el resto.'
      )
    ).toBeInTheDocument()
  })

  it('renders no truncation notice when response.truncated is absent', () => {
    render(<ValidateStep response={buildResponse()} onContinue={onContinue} onBack={onBack} />)

    expect(screen.queryByText(/se validaron las primeras/i)).not.toBeInTheDocument()
  })
})
