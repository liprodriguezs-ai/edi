/**
 * Tests para ResponsiveDataTable.
 *
 * Nota de arquitectura: el componente renderiza AMBAS ramas (desktop tabla + mobile cards)
 * en el DOM simultáneamente — CSS decide cuál es visible. Los tests validan la ESTRUCTURA
 * y reachability sin depender del motor CSS de jsdom (que no ejecuta media queries).
 */
import { ResponsiveDataTable } from '@/shared/components/molecules/ResponsiveDataTable'
import type { ResponsiveField, ResponsiveBreakpoint } from '@/shared/components/molecules/ResponsiveDataTable'
import { TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { IEntity } from '@/shared/interfaces/Entity'
import { render, screen, fireEvent, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

// ── Fixtures ─────────────────────────────────────────────────────────────────

interface ITestEntity extends IEntity {
  id: string
  name: string
  description: string
}

const ROWS: ITestEntity[] = [
  { id: '1', name: 'Entidad Uno', description: 'Descripción uno' },
  { id: '2', name: 'Entidad Dos', description: 'Descripción dos' },
]

const TEST_HEADER = (
  <TableHeader>
    <TableRow>
      <TableHead scope="col">Nombre</TableHead>
      <TableHead scope="col">Descripción</TableHead>
      <TableHead scope="col"></TableHead>
    </TableRow>
  </TableHeader>
)

function renderRow(entity: ITestEntity) {
  return (
    <tr key={entity.id}>
      <td>{entity.name}</td>
      <td>{entity.description}</td>
      <td>
        <button aria-label={`Editar ${entity.name}`}>Editar</button>
      </td>
    </tr>
  )
}

function renderCardTitle(entity: ITestEntity) {
  return entity.name
}

function renderCardFields(entity: ITestEntity): ResponsiveField[] {
  return [
    { label: 'Nombre', value: entity.name },
    { label: 'Descripción', value: entity.description },
  ]
}

function renderActions(entity: ITestEntity) {
  return <button aria-label={`Editar ${entity.name}`}>Editar</button>
}

// ── Tests ─────────────────────────────────────────────────────────────────────

describe('ResponsiveDataTable', () => {
  // ── Caso 1: tabla desktop presente ──────────────────────────────────────────
  it('renderiza la tabla desktop con header y filas de datos', () => {
    render(
      <ResponsiveDataTable<ITestEntity>
        rows={ROWS}
        header={TEST_HEADER}
        renderRow={renderRow}
        renderCardTitle={renderCardTitle}
        renderCardFields={renderCardFields}
      />,
    )

    // Una sola tabla en el DOM
    const tables = screen.getAllByRole('table')
    expect(tables).toHaveLength(1)

    // Cabeceras + filas de datos (1 header row + 2 data rows)
    const rows = screen.getAllByRole('row')
    expect(rows.length).toBeGreaterThanOrEqual(3)
  })

  // ── Caso 2: scope="col" en las cabeceras ────────────────────────────────────
  it('los columnheader tienen scope="col" cuando se pasan con <TableHead scope="col">', () => {
    render(
      <ResponsiveDataTable<ITestEntity>
        rows={ROWS}
        header={TEST_HEADER}
        renderRow={renderRow}
        renderCardTitle={renderCardTitle}
        renderCardFields={renderCardFields}
      />,
    )

    const columnHeaders = screen.getAllByRole('columnheader')
    // Todos los th con texto significativo deben tener scope="col"
    const withScope = columnHeaders.filter(
      (th) => th.getAttribute('scope') === 'col',
    )
    expect(withScope.length).toBeGreaterThan(0)
    // Todos los columnheaders deben tener scope="col"
    columnHeaders.forEach((th) => {
      expect(th).toHaveAttribute('scope', 'col')
    })
  })

  // ── Caso 3: cards presentes con <dl>/<dt> ────────────────────────────────────
  it('renderiza la lista de cards con dl/dt para cada entidad', () => {
    render(
      <ResponsiveDataTable<ITestEntity>
        rows={ROWS}
        header={TEST_HEADER}
        renderRow={renderRow}
        renderCardTitle={renderCardTitle}
        renderCardFields={renderCardFields}
      />,
    )

    // Lista de cards con role="list"
    const list = screen.getByRole('list')
    expect(list.tagName.toLowerCase()).toBe('ul')

    // Un <li> por entidad
    const items = screen.getAllByRole('listitem')
    expect(items).toHaveLength(ROWS.length)

    // Cada card tiene <dl> con etiquetas
    const termElements = screen.getAllByRole('term')
    const termTexts = termElements.map((dt) => dt.textContent)
    expect(termTexts).toContain('Nombre')
    expect(termTexts).toContain('Descripción')
  })

  // ── Caso 4: acciones alcanzables en ambas ramas ──────────────────────────────
  it('las acciones son accesibles en AMBAS representaciones (tabla y cards)', () => {
    render(
      <ResponsiveDataTable<ITestEntity>
        rows={ROWS}
        header={TEST_HEADER}
        renderRow={renderRow}
        renderCardTitle={renderCardTitle}
        renderCardFields={renderCardFields}
        renderActions={renderActions}
      />,
    )

    // Hay 2 entidades × 2 ramas (tabla + card) = 4 botones "Editar"
    const editarButtons = screen.getAllByRole('button', { name: /editar/i })
    expect(editarButtons).toHaveLength(ROWS.length * 2)
  })

  // ── Caso 5: onRowClick en card body, NO se dispara al click en acción ─────────
  it('onRowClick se dispara al hacer click en el cuerpo de la card pero NO en el botón de acción', () => {
    const onRowClick = vi.fn()

    render(
      <ResponsiveDataTable<ITestEntity>
        rows={[ROWS[0]]}
        header={TEST_HEADER}
        renderRow={renderRow}
        renderCardTitle={renderCardTitle}
        renderCardFields={renderCardFields}
        renderActions={renderActions}
        onRowClick={onRowClick}
      />,
    )

    // Buscamos el botón de acción de la card usando within(list) para no depender de índices
    const list = screen.getByRole('list')
    const cardActionButton = within(list).getByRole('button', { name: /editar entidad uno/i })
    fireEvent.click(cardActionButton)
    expect(onRowClick).not.toHaveBeenCalled()

    // Click en un <dt> de la card (dentro del div clickeable) SÍ debe llamar onRowClick
    // Los <dt> sólo existen dentro del branch de cards
    const terms = screen.getAllByRole('term')
    fireEvent.click(terms[0])
    expect(onRowClick).toHaveBeenCalledWith(ROWS[0])
  })

  // ── Caso 5b: la card es activable con teclado cuando onRowClick está definido ──
  it('la card es activable con Enter/Space cuando onRowClick está definido', () => {
    const onRowClick = vi.fn()

    render(
      <ResponsiveDataTable<ITestEntity>
        rows={[ROWS[0]]}
        header={TEST_HEADER}
        renderRow={renderRow}
        renderCardTitle={renderCardTitle}
        renderCardFields={renderCardFields}
        renderActions={renderActions}
        onRowClick={onRowClick}
      />,
    )

    const list = screen.getByRole('list')
    // La card debe tener role="button" y ser alcanzable por teclado
    const cardButton = within(list).getByRole('button', { name: /editar entidad uno/i })
    // El <div> clickeable de la card (no el botón de acción interno)
    const cardItem = within(list).getAllByRole('listitem')[0]
    const cardClickable = cardItem.querySelector('[role="button"][tabindex="0"]')
    expect(cardClickable).toBeTruthy()

    // Enter activa onRowClick
    fireEvent.keyDown(cardClickable!, { key: 'Enter' })
    expect(onRowClick).toHaveBeenCalledTimes(1)
    expect(onRowClick).toHaveBeenCalledWith(ROWS[0])

    onRowClick.mockClear()

    // Space activa onRowClick
    fireEvent.keyDown(cardClickable!, { key: ' ' })
    expect(onRowClick).toHaveBeenCalledTimes(1)

    onRowClick.mockClear()

    // keyDown en el botón de acción NO propaga a la card
    fireEvent.keyDown(cardButton, { key: 'Enter' })
    expect(onRowClick).not.toHaveBeenCalled()
  })

  // ── Caso 5c: sin onRowClick, la card NO tiene role="button" ni tabIndex ────────
  it('sin onRowClick, la card no es interactiva (sin role=button ni tabindex)', () => {
    render(
      <ResponsiveDataTable<ITestEntity>
        rows={[ROWS[0]]}
        header={TEST_HEADER}
        renderRow={renderRow}
        renderCardTitle={renderCardTitle}
        renderCardFields={renderCardFields}
      />,
    )

    const list = screen.getByRole('list')
    const cardItem = within(list).getAllByRole('listitem')[0]
    // Sin onRowClick no debe existir ningún div con role="button" en la card
    const interactiveCard = cardItem.querySelector('[role="button"]')
    expect(interactiveCard).toBeNull()
  })

  // ── Caso 6: matchMedia mock no lanza errores ─────────────────────────────────
  it('el componente se monta sin errores aunque window.matchMedia esté presente (stub de setup.ts)', () => {
    expect(() => {
      render(
        <ResponsiveDataTable<ITestEntity>
          rows={ROWS}
          header={TEST_HEADER}
          renderRow={renderRow}
          renderCardTitle={renderCardTitle}
          renderCardFields={renderCardFields}
        />,
      )
    }).not.toThrow()

    // El stub de setup.ts es accesible y retorna matches=false
    const result = window.matchMedia('(max-width: 767px)')
    expect(result.matches).toBe(false)
  })

  // ── Caso 7: rows vacío ──────────────────────────────────────────────────────
  it('renderiza sin errores y sin <li> cuando rows está vacío', () => {
    render(
      <ResponsiveDataTable<ITestEntity>
        rows={[]}
        header={TEST_HEADER}
        renderRow={renderRow}
        renderCardTitle={renderCardTitle}
        renderCardFields={renderCardFields}
      />,
    )

    // La tabla existe pero sin filas de datos (solo header)
    expect(screen.getByRole('table')).toBeInTheDocument()

    // No debe haber listitem en la lista de cards
    expect(screen.queryAllByRole('listitem')).toHaveLength(0)
  })

  // ── Caso 8: breakpoint="lg" usa clases lg: ──────────────────────────────────
  it('con breakpoint="lg" el wrapper de tabla usa hidden lg:block y el de cards lg:hidden', () => {
    const { container } = render(
      <ResponsiveDataTable<ITestEntity>
        rows={ROWS}
        header={TEST_HEADER}
        renderRow={renderRow}
        renderCardTitle={renderCardTitle}
        renderCardFields={renderCardFields}
        breakpoint={'lg' as ResponsiveBreakpoint}
      />,
    )

    // El wrapper de la tabla desktop debe contener la clase lg:block (y hidden)
    const tableWrapper = container.querySelector('div')
    expect(tableWrapper).not.toBeNull()
    expect(tableWrapper!.className).toContain('hidden')
    expect(tableWrapper!.className).toContain('lg:block')
    // NO debe contener md:block
    expect(tableWrapper!.className).not.toContain('md:block')

    // El wrapper de cards debe contener lg:hidden
    const cardList = screen.getByRole('list')
    expect(cardList.className).toContain('lg:hidden')
    // NO debe contener md:hidden
    expect(cardList.className).not.toContain('md:hidden')
  })

  // ── Caso 9: breakpoint por defecto (sin prop) usa clases lg: ────────────────
  it('sin prop breakpoint (default lg) el wrapper de tabla usa hidden lg:block y el de cards lg:hidden', () => {
    const { container } = render(
      <ResponsiveDataTable<ITestEntity>
        rows={ROWS}
        header={TEST_HEADER}
        renderRow={renderRow}
        renderCardTitle={renderCardTitle}
        renderCardFields={renderCardFields}
      />,
    )

    const tableWrapper = container.querySelector('div')
    expect(tableWrapper).not.toBeNull()
    expect(tableWrapper!.className).toContain('hidden')
    expect(tableWrapper!.className).toContain('lg:block')
    expect(tableWrapper!.className).not.toContain('md:block')

    const cardList = screen.getByRole('list')
    expect(cardList.className).toContain('lg:hidden')
    expect(cardList.className).not.toContain('md:hidden')
  })
})
