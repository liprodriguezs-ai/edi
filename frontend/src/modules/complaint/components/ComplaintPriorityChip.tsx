interface ComplaintPriorityChipProps {
  name?: string | null
  color?: string | null
}

/** Chip de prioridad — usa el color hex tenant-scoped (complaint_priority.color) si existe. */
export function ComplaintPriorityChip({ name, color }: ComplaintPriorityChipProps) {
  if (!name) return <span className='text-muted-foreground'>—</span>

  const hex = color ?? '#6B7280'

  return (
    <span
      className='inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium'
      style={{ backgroundColor: `${hex}20`, color: hex }}
    >
      <i
        aria-hidden
        className='h-1.5 w-1.5 flex-none rounded-full'
        style={{ backgroundColor: hex }}
      />
      {name}
    </span>
  )
}
