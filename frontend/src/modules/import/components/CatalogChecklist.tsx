import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { IconAlertTriangle, IconCheck } from '@tabler/icons-react'
import { Link } from 'react-router-dom'

interface CatalogCheckItem {
  label: string
  catalogType: CatalogType
  route: string
}

const CATALOG_ITEMS: CatalogCheckItem[] = [
  { label: 'Tipos de unidad', catalogType: CatalogType.UNIT_TYPE, route: '/unit-type' },
  { label: 'Estados de unidad', catalogType: CatalogType.UNIT_STATUS, route: '/unit-status' },
  { label: 'Cuentas bancarias', catalogType: CatalogType.BANK_ACCOUNT, route: '/bank-account' },
]

/**
 * CatalogChecklist — shows the count of required catalog entries for the import.
 *
 * Green badge when the catalog has records; amber warning when empty.
 * Each item links to the management screen so the user can add records if needed.
 */
export function CatalogChecklist() {
  const catalogRequest = {
    [CatalogType.UNIT_TYPE]: {},
    [CatalogType.UNIT_STATUS]: {},
    [CatalogType.BANK_ACCOUNT]: {},
  }

  const { catalogs, isLoading } = useCatalogs(catalogRequest)

  return (
    <div>
      <h3 className="mb-3 text-sm font-semibold text-muted-foreground uppercase tracking-wide">
        Catálogos requeridos
      </h3>
      <p className="mb-3 text-xs text-muted-foreground">
        Si falta alguno, el import lo crea con aviso, pero es mejor tenerlos configurados antes.
      </p>
      <ul className="space-y-2" data-testid="catalog-checklist">
        {CATALOG_ITEMS.map((item) => {
          const entries = catalogs[item.catalogType] ?? []
          const count = entries.length
          const isEmpty = !isLoading && count === 0

          return (
            <li key={item.catalogType} className="flex items-center justify-between gap-3">
              <Link
                to={item.route}
                className="text-sm font-medium text-primary underline-offset-2 hover:underline"
                data-testid={`catalog-link-${item.catalogType}`}
              >
                {item.label}
              </Link>
              {isLoading ? (
                <span className="text-xs text-muted-foreground">Cargando…</span>
              ) : isEmpty ? (
                <span
                  className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-400"
                  data-testid={`catalog-badge-empty-${item.catalogType}`}
                >
                  <IconAlertTriangle className="h-3 w-3" />
                  Vacío
                </span>
              ) : (
                <span
                  className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-500"
                  data-testid={`catalog-badge-count-${item.catalogType}`}
                >
                  <IconCheck className="h-3 w-3" />
                  {count}
                </span>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
