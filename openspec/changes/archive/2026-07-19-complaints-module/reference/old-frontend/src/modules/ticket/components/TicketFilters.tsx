import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { Plus, Search } from 'lucide-react'

interface TicketFiltersProps {
  onCreateClick: () => void
  searchTerm: string
  onSearchTermChange: (_value: string) => void
}

export const TicketFilters = ({ onCreateClick, searchTerm, onSearchTermChange }: TicketFiltersProps) => {
  return (
    <div className="flex flex-wrap items-center gap-3">
      {/* Buscar por folio o asunto */}
      <div className="relative flex-1 min-w-0 sm:min-w-[200px] sm:max-w-sm">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
        <Input
          placeholder="Buscar por folio o asunto..."
          value={searchTerm}
          onChange={(e) => onSearchTermChange(e.target.value)}
          className="pl-9"
        />
      </div>

      {/* Boton nuevo ticket */}
      <PermissionGuard permission="create.ticket">
        <Button onClick={onCreateClick} className="flex items-center gap-2 w-full sm:w-auto">
          <Plus className="h-4 w-4" />
          Nuevo ticket
        </Button>
      </PermissionGuard>
    </div>
  )
}
