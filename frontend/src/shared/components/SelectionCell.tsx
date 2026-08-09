import { Checkbox } from '@/components/ui/checkbox'
import { TableCell, TableHead } from '@/components/ui/table'

interface SelectionCellProps {
  checked: boolean
  onToggle: () => void
  ariaLabel?: string
}

export function SelectionCell({ checked, onToggle, ariaLabel = 'Seleccionar fila' }: SelectionCellProps) {
  return (
    <TableCell
      className="w-[40px]"
      onClick={(event) => {
        event.stopPropagation()
      }}
    >
      <Checkbox
        checked={checked}
        onCheckedChange={onToggle}
        aria-label={ariaLabel}
      />
    </TableCell>
  )
}

interface SelectionHeaderCellProps {
  checked: boolean
  indeterminate?: boolean
  onToggle: () => void
  ariaLabel?: string
}

export function SelectionHeaderCell({
  checked,
  indeterminate = false,
  onToggle,
  ariaLabel = 'Seleccionar todo',
}: SelectionHeaderCellProps) {
  return (
    <TableHead className="w-[40px]">
      <Checkbox
        checked={indeterminate ? 'indeterminate' : checked}
        onCheckedChange={onToggle}
        aria-label={ariaLabel}
      />
    </TableHead>
  )
}
