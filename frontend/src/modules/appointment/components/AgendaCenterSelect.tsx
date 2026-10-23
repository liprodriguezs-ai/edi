import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { useMemo, useState } from 'react'

/** Value of the "all centers" option of the agenda filter. */
export const ALL_CENTERS = 'all'

const ALL_LABEL = 'Todos los centros'

interface AgendaCenterSelectProps {
  value: string
  onChange: (_value: string) => void
}

/**
 * Health-center filter of the agenda. The centers load when the list opens, or
 * right away when a specific center is already chosen (its name is needed for
 * the closed select); with "Todos los centros" nothing is requested up front.
 */
export function AgendaCenterSelect({ value, onChange }: AgendaCenterSelectProps) {
  const [opened, setOpened] = useState(false)
  const { catalogs } = useCatalogs(
    useMemo(() => ({ [CatalogType.HEALTH_CENTER]: { _sort: 'name' } }), []),
    { enabled: opened || value !== ALL_CENTERS }
  )
  const centers = catalogs[CatalogType.HEALTH_CENTER] ?? []
  const label = value === ALL_CENTERS ? ALL_LABEL : (centers.find((center) => center.id === value)?.name ?? '')

  return (
    <div className="w-full sm:w-72">
      <label htmlFor="agenda-health-center" className="mb-1 block text-xs font-medium text-[var(--ink-2)]">
        Centro de salud
      </label>
      <Select
        value={value}
        onValueChange={onChange}
        onOpenChange={(open) => {
          if (open) setOpened(true)
        }}
      >
        <SelectTrigger id="agenda-health-center" className="w-full">
          <SelectValue placeholder={ALL_LABEL}>{label}</SelectValue>
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={ALL_CENTERS}>{ALL_LABEL}</SelectItem>
          {centers.map((center) => (
            <SelectItem key={center.id} value={center.id}>
              {center.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  )
}
