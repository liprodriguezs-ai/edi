import { Badge } from '@/components/ui/badge'
import { ETicketImportance } from '@/modules/ticket/schema'
import type { ITicketImportance } from '@/modules/ticket/schema'

const IMPORTANCE_CONFIG: Record<string, { label: string; className: string }> = {
  [ETicketImportance.TALL]: { label: 'Alta', className: 'bg-red-100 text-red-700 border-red-200' },
  [ETicketImportance.MEDIUM]: { label: 'Media', className: 'bg-yellow-100 text-yellow-700 border-yellow-200' },
  [ETicketImportance.LOW]: { label: 'Baja', className: 'bg-green-100 text-green-700 border-green-200' },
  [ETicketImportance.UNASSIGNED]: { label: 'Sin asignar', className: 'bg-gray-100 text-gray-500 border-gray-200' },
}

interface ImportanceBadgeProps {
  importanceId?: string
  importance?: ITicketImportance
}

export const ImportanceBadge = ({ importanceId, importance }: ImportanceBadgeProps) => {
  const id = importanceId ?? importance?.id ?? ''
  const config = IMPORTANCE_CONFIG[id]
  if (!config) return null

  return (
    <Badge variant="outline" className={`text-xs font-medium ${config.className}`}>
      {importance?.name ?? config.label}
    </Badge>
  )
}
