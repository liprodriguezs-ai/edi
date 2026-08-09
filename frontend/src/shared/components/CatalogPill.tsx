const FALLBACK_COLOR = '#6B7280'

interface CatalogPillProps {
  name?: string | null
  color?: string | null
}

/**
 * Pill para filas de catálogos tenant-scoped: usa el color que el organización
 * configuró en la fila (nunca un mapeo por UUID — los ids varían por tenant).
 */
export const CatalogPill = ({ name, color }: CatalogPillProps) => {
  const pillColor = color || FALLBACK_COLOR

  return (
    <span
      className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11.5px] font-semibold"
      style={{ backgroundColor: `${pillColor}1A`, color: pillColor }}
    >
      <i aria-hidden className="size-1.5 rounded-full" style={{ backgroundColor: pillColor }} />
      {name ?? '—'}
    </span>
  )
}
