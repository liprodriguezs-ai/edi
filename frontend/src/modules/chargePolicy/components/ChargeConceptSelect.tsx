import type { IChargeConcept } from '@/modules/chargeConcept/schema'
import { ChargeConceptResource } from '@/modules/chargeConcept/services/ChargeConceptResource'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'

interface ChargeConceptSelectProps {
  value: string
  onChange: (_id: string) => void
  disabled?: boolean
  id?: string
}

/**
 * Selector reutilizable del concepto de cobro al que pertenece una política.
 * Encapsula la query de conceptos + el `<SearchSelect>` (con búsqueda). Si no hay
 * conceptos todavía, muestra un aviso con enlace para crear uno.
 */
export function ChargeConceptSelect({ value, onChange, disabled, id }: ChargeConceptSelectProps) {
  const { data: conceptsPage, isLoading } = useQuery({
    queryKey: ['charge-concept', 'select'],
    queryFn: () =>
      ChargeConceptResource.list({
        sort: [{ field: 'code', direction: 'asc' }],
        limit: 100,
      }),
  })
  const concepts: IChargeConcept[] = conceptsPage?.data ?? []

  return (
    <div className='space-y-2'>
      <SearchSelect<IChargeConcept>
        id={id}
        value={value}
        onChange={(selected) => onChange((selected as string | null) ?? '')}
        options={concepts}
        getOptionLabel={(concept) => `${concept.code} · ${concept.name}`}
        isDisabled={disabled}
        isLoading={isLoading}
        placeholder={isLoading ? 'Cargando conceptos…' : 'Selecciona un concepto…'}
      />

      {!isLoading && concepts.length === 0 && (
        <p className='text-[12.5px] text-[var(--ink-3)]'>
          No hay conceptos de cobro todavía. Crea uno primero en{' '}
          <Link to='/charge-concept' className='font-medium text-[var(--green-deep)] underline-offset-2 hover:underline'>
            Conceptos de cobro
          </Link>
          .
        </p>
      )}
    </div>
  )
}
