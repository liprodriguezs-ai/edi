/**
 * Tests de `autoSelectSingle` en SearchSelect.
 *
 * Se prueban acá y no con Playwright a propósito: el caso depende de que el
 * catálogo traiga EXACTAMENTE una opción, y los catálogos sembrados del cluster
 * de desarrollo traen varias. Forzar ese estado desde el navegador sería tocar
 * la base; acá es un prop.
 */
import { SearchSelect } from '@/shared/components/SearchSelect'
import { render, screen } from '@testing-library/react'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'

const ONE = [{ id: 'u-1', name: 'Casa 1' }]
const TWO = [
  { id: 'u-1', name: 'Casa 1' },
  { id: 'u-2', name: 'Casa 2' },
]

describe('SearchSelect — autoSelectSingle', () => {
  it('con UNA sola opción la selecciona sola', () => {
    const onChange = vi.fn()
    render(<SearchSelect value='' onChange={onChange} options={ONE} autoSelectSingle />)
    expect(onChange).toHaveBeenCalledWith('u-1')
  })

  it('con DOS opciones no elige por el usuario', () => {
    const onChange = vi.fn()
    render(<SearchSelect value='' onChange={onChange} options={TWO} autoSelectSingle />)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('sin el prop no autoselecciona (es opt-in, los filtros siguen en "Todos")', () => {
    const onChange = vi.fn()
    render(<SearchSelect value='' onChange={onChange} options={ONE} />)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('no pisa un valor ya elegido', () => {
    const onChange = vi.fn()
    render(<SearchSelect value='u-9' onChange={onChange} options={ONE} autoSelectSingle />)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('no aplica en multiple', () => {
    const onChange = vi.fn()
    render(<SearchSelect value={[]} onChange={onChange} options={ONE} autoSelectSingle multiple />)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('espera al catálogo: dispara recién cuando la única opción llega', () => {
    const onChange = vi.fn()
    const { rerender } = render(
      <SearchSelect value='' onChange={onChange} options={[]} autoSelectSingle />
    )
    expect(onChange).not.toHaveBeenCalled()

    rerender(<SearchSelect value='' onChange={onChange} options={ONE} autoSelectSingle />)
    expect(onChange).toHaveBeenCalledWith('u-1')
  })

  it('pinta la única opción en el control', () => {
    function Harness() {
      const [value, setValue] = useState('')
      return (
        <SearchSelect
          value={value}
          onChange={(v) => setValue((v as string | null) ?? '')}
          options={ONE}
          autoSelectSingle
        />
      )
    }
    render(<Harness />)
    expect(screen.getByText('Casa 1')).toBeInTheDocument()
  })

  it('si el usuario limpia el campo, NO se lo volvemos a llenar', () => {
    const seen: string[] = []
    function Harness() {
      const [value, setValue] = useState('')
      return (
        <SearchSelect
          value={value}
          onChange={(v) => {
            const next = (v as string | null) ?? ''
            seen.push(next)
            setValue(next)
          }}
          options={ONE}
          autoSelectSingle
          isClearable
        />
      )
    }
    const { rerender } = render(<Harness />)
    expect(seen).toEqual(['u-1'])

    // Un re-render posterior no debe volver a disparar la autoselección.
    rerender(<Harness />)
    expect(seen).toEqual(['u-1'])
  })
})
