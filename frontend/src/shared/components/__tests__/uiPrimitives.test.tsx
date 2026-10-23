import { fireEvent, render, screen } from '@testing-library/react'
import { Info } from 'lucide-react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

import { Button } from '@/components/ui/button'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { Callout } from '@/shared/components/atoms/Callout'
import { KpiTile } from '@/shared/components/atoms/KpiTile'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { ResultColorPill } from '@/shared/components/atoms/ResultColorPill'
import { resultColorName } from '@/shared/utils/resultColor'
import { SectionLabel } from '@/shared/components/atoms/SectionLabel'
import { DescriptionItem, DescriptionList } from '@/shared/components/molecules/DescriptionList'
import { FormFooter } from '@/shared/components/molecules/FormFooter'
import { InlineEmpty } from '@/shared/components/molecules/InlineEmpty'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { Panel } from '@/shared/components/molecules/Panel'
import { SegmentedControl } from '@/shared/components/molecules/SegmentedControl'
import { SelectableCard } from '@/shared/components/molecules/SelectableCard'

describe('Button loading', () => {
  it('disables the button and marks it busy while loading', () => {
    const onClick = vi.fn()
    render(
      <Button loading onClick={onClick}>
        Guardar
      </Button>
    )
    const button = screen.getByRole('button', { name: /guardar/i })
    expect(button).toBeDisabled()
    expect(button).toHaveAttribute('aria-busy', 'true')
    fireEvent.click(button)
    expect(onClick).not.toHaveBeenCalled()
  })

  it('keeps the previous behavior when not loading', () => {
    const onClick = vi.fn()
    render(<Button onClick={onClick}>Guardar</Button>)
    const button = screen.getByRole('button', { name: 'Guardar' })
    expect(button).toBeEnabled()
    expect(button).not.toHaveAttribute('aria-busy')
    fireEvent.click(button)
    expect(onClick).toHaveBeenCalledOnce()
  })
})

describe('ResultColorPill', () => {
  it.each([
    ['green', 'Verde'],
    ['yellow', 'Amarillo'],
    ['red', 'Rojo'],
  ])('renders the Spanish name for %s', (key, name) => {
    render(<ResultColorPill colorKey={key} />)
    expect(screen.getByText(name)).toBeInTheDocument()
    expect(resultColorName(key)).toBe(name)
  })

  it('renders "Sin calificar" when there is no color', () => {
    render(<ResultColorPill colorKey={null} />)
    expect(screen.getByText('Sin calificar')).toBeInTheDocument()
  })

  it('accepts a custom label', () => {
    render(<ResultColorPill colorKey='red' label='Riesgo de retraso' />)
    expect(screen.getByText('Riesgo de retraso')).toBeInTheDocument()
  })
})

describe('InlineEmpty / InlineLoading', () => {
  it('announces the empty message as status', () => {
    render(<InlineEmpty>Sin registros.</InlineEmpty>)
    expect(screen.getByRole('status')).toHaveTextContent('Sin registros.')
  })

  it('renders a busy loading region', () => {
    render(<InlineLoading name='test-loading' />)
    expect(screen.getByLabelText('Cargando')).toHaveAttribute('aria-busy', 'true')
  })
})

describe('DescriptionList', () => {
  it('renders label/value pairs as dt/dd', () => {
    render(
      <DescriptionList>
        <DescriptionItem label='Folio' value='A-001' mono />
        <DescriptionItem label='Edad' value='12 meses' hint='Edad corregida' />
      </DescriptionList>
    )
    expect(screen.getByText('Folio').tagName).toBe('DT')
    expect(screen.getByText('A-001').tagName).toBe('DD')
    expect(screen.getByText('Edad corregida')).toBeInTheDocument()
  })
})

describe('Panel / SectionLabel / FormFooter', () => {
  it('renders title, eyebrow and actions', () => {
    render(
      <Panel eyebrow='Resumen' title='Datos' actions={<button type='button'>Editar</button>}>
        contenido
      </Panel>
    )
    expect(screen.getByText('Resumen')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Datos' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Editar' })).toBeInTheDocument()
    expect(screen.getByText('contenido')).toBeInTheDocument()
  })

  it('SectionLabel renders the given heading level', () => {
    render(<SectionLabel as='h2'>Ejes</SectionLabel>)
    expect(screen.getByRole('heading', { level: 2, name: 'Ejes' })).toBeInTheDocument()
  })

  it('FormFooter renders its children', () => {
    render(
      <FormFooter>
        <button type='submit'>Guardar</button>
      </FormFooter>
    )
    expect(screen.getByRole('button', { name: 'Guardar' })).toBeInTheDocument()
  })
})

describe('SegmentedControl', () => {
  it('marks the selected option and emits changes', () => {
    const onChange = vi.fn()
    render(
      <SegmentedControl
        aria-label='Periodicidad'
        value='month'
        onValueChange={onChange}
        options={[
          { value: 'month', label: 'Mensual' },
          { value: 'year', label: 'Anual' },
        ]}
      />
    )
    expect(screen.getByRole('radio', { name: 'Mensual' })).toHaveAttribute('aria-checked', 'true')
    fireEvent.click(screen.getByRole('radio', { name: 'Anual' }))
    expect(onChange).toHaveBeenCalledWith('year')
  })

  it('ignores deselecting the active option', () => {
    const onChange = vi.fn()
    render(
      <SegmentedControl
        aria-label='Periodicidad'
        value='month'
        onValueChange={onChange}
        options={[
          { value: 'month', label: 'Mensual' },
          { value: 'year', label: 'Anual' },
        ]}
      />
    )
    fireEvent.click(screen.getByRole('radio', { name: 'Mensual' }))
    expect(onChange).not.toHaveBeenCalled()
  })
})

describe('SelectableCard', () => {
  it('reflects selection and calls onSelect', () => {
    const onSelect = vi.fn()
    render(
      <SelectableCard selected onSelect={onSelect}>
        Plan básico
      </SelectableCard>
    )
    const card = screen.getByRole('button', { name: 'Plan básico' })
    expect(card).toHaveAttribute('aria-pressed', 'true')
    fireEvent.click(card)
    expect(onSelect).toHaveBeenCalledOnce()
  })
})

describe('PageHeader', () => {
  it('keeps title and description', () => {
    render(<PageHeader title='Niños' description='Registro de niños' />)
    expect(screen.getByRole('heading', { level: 1, name: 'Niños' })).toBeInTheDocument()
    expect(screen.getByText('Registro de niños')).toBeInTheDocument()
  })

  it('renders back link and meta', () => {
    render(
      <MemoryRouter>
        <PageHeader title='Prueba EDI' back={{ to: '/screening', label: 'Pruebas EDI' }} meta='Folio A-001' />
      </MemoryRouter>
    )
    expect(screen.getByRole('link', { name: /pruebas edi/i })).toHaveAttribute('href', '/screening')
    expect(screen.getByText('Folio A-001')).toBeInTheDocument()
  })
})

describe('Callout tones', () => {
  it('renders children for every tone', () => {
    render(
      <>
        <Callout icon={Info}>verde</Callout>
        <Callout icon={Info} tone='info'>
          info
        </Callout>
        <Callout icon={Info} tone='warning'>
          aviso
        </Callout>
      </>
    )
    expect(screen.getByText('verde')).toBeInTheDocument()
    expect(screen.getByText('info')).toBeInTheDocument()
    expect(screen.getByText('aviso')).toBeInTheDocument()
  })
})

describe('KpiTile', () => {
  it('renders label, value and optional detail', () => {
    render(<KpiTile label='Pruebas' value={12} detail='este mes' />)
    expect(screen.getByText('Pruebas')).toBeInTheDocument()
    expect(screen.getByText('12')).toBeInTheDocument()
    expect(screen.getByText('este mes')).toBeInTheDocument()
  })
})

describe('ConfirmDialog children', () => {
  it('renders extra fields between description and footer', () => {
    render(
      <ConfirmDialog isOpen title='Cancelar seguimiento' onConfirm={vi.fn()} onCancel={vi.fn()}>
        <label htmlFor='reason'>Motivo</label>
        <textarea id='reason' />
      </ConfirmDialog>
    )
    expect(screen.getByLabelText('Motivo')).toBeInTheDocument()
  })

  it('can disable confirm while keeping cancel enabled', () => {
    render(
      <ConfirmDialog isOpen title='Invalidar' confirmDisabled onConfirm={vi.fn()} onCancel={vi.fn()} />
    )
    expect(screen.getByRole('button', { name: 'Confirmar' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Cancelar' })).toBeEnabled()
  })
})

describe('ConfirmDialog options', () => {
  it('shows a custom loading label', () => {
    render(
      <ConfirmDialog isOpen isLoading loadingLabel='Invalidando…' onConfirm={vi.fn()} onCancel={vi.fn()} />
    )
    expect(screen.getByRole('button', { name: 'Invalidando…' })).toBeDisabled()
  })

  it('acknowledge mode renders a single button that confirms', () => {
    const onConfirm = vi.fn()
    render(
      <ConfirmDialog isOpen hideCancel title='Sin permiso' confirmLabel='Entendido' onConfirm={onConfirm} onCancel={vi.fn()} />
    )
    expect(screen.queryByRole('button', { name: 'Cancelar' })).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Entendido' }))
    expect(onConfirm).toHaveBeenCalledOnce()
  })

  it('destructive confirm keeps the default copy (no delete wording)', () => {
    render(<ConfirmDialog isOpen destructive title='Invalidar' onConfirm={vi.fn()} onCancel={vi.fn()} />)
    expect(screen.getByRole('button', { name: 'Confirmar' })).toHaveAttribute('data-variant', 'destructive')
  })
})

describe('Panel accessibility', () => {
  it('names the section region after its title', () => {
    render(<Panel title='Vencimiento'>contenido</Panel>)
    expect(screen.getByRole('region', { name: 'Vencimiento' })).toBeInTheDocument()
  })
})

describe('SelectableCard radio mode', () => {
  it('exposes radio semantics when used inside a radiogroup', () => {
    render(
      <div role='radiogroup' aria-label='Plan destino'>
        <SelectableCard role='radio' selected onSelect={vi.fn()}>
          Plan básico
        </SelectableCard>
      </div>
    )
    const card = screen.getByRole('radio', { name: 'Plan básico' })
    expect(card).toHaveAttribute('aria-checked', 'true')
    expect(card).not.toHaveAttribute('aria-pressed')
  })
})
