import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { IPagination } from '@/shared/interfaces/list.types'
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react'

interface PaginationProps {
  pagination: IPagination
  onPageChanged: (_page: number, _size?: number) => void
  pageSizeOptions?: number[]
  selectedCount?: number
}

export const Pagination = ({
  pagination,
  onPageChanged,
  pageSizeOptions = [20, 50, 100],
  selectedCount,
}: PaginationProps) => {
  const { page: currentPage, pages: totalPages, total: totalItems, per_page: pageSize } = pagination

  if (totalItems === 0 || totalPages <= 1) return null

  return (
    <div className='flex items-center justify-center gap-3 border-t px-2 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:justify-between sm:py-4 sm:pb-[max(1rem,env(safe-area-inset-bottom))] sticky bottom-0 bg-background z-10'>
      {/* Total count: desktop only — noise on mobile where the goal is a single compact row */}
      <div className='hidden text-sm text-muted-foreground sm:block'>
        {selectedCount !== undefined
          ? `${selectedCount} de ${totalItems} fila(s) seleccionada(s).`
          : `${totalItems} fila(s) en total.`}
      </div>

      <div className='flex items-center gap-3 sm:gap-6 lg:gap-8'>
        {/* Page size selector: desktop only — a power-user control almost never used on a phone */}
        <div className='hidden items-center gap-2 sm:flex'>
          <p className='text-sm font-medium whitespace-nowrap'>Filas por página</p>
          <Select
            value={String(pageSize)}
            onValueChange={(value) => onPageChanged(1, Number(value))}
          >
            <SelectTrigger size='sm' className='w-[70px]'>
              <SelectValue placeholder={String(pageSize)} />
            </SelectTrigger>
            <SelectContent side='top'>
              {pageSizeOptions.map((size) => (
                <SelectItem key={size} value={String(size)}>
                  {size}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className='flex items-center gap-2'>
          <p className='text-sm font-medium whitespace-nowrap'>
            Página {currentPage} de {totalPages}
          </p>
          <div className='flex items-center gap-1'>
            {/* First page: desktop only — redundant with prev on small page counts */}
            <Button
              variant='outline'
              size='icon-sm'
              onClick={() => onPageChanged(1)}
              disabled={currentPage <= 1}
              aria-label='Primera página'
              className='hidden sm:inline-flex'
            >
              <ChevronsLeft className='size-4' />
            </Button>
            <Button
              variant='outline'
              size='icon-sm'
              onClick={() => onPageChanged(currentPage - 1)}
              disabled={currentPage <= 1}
              aria-label='Página anterior'
            >
              <ChevronLeft className='size-4' />
            </Button>
            <Button
              variant='outline'
              size='icon-sm'
              onClick={() => onPageChanged(currentPage + 1)}
              disabled={currentPage >= totalPages}
              aria-label='Página siguiente'
            >
              <ChevronRight className='size-4' />
            </Button>
            {/* Last page: desktop only */}
            <Button
              variant='outline'
              size='icon-sm'
              onClick={() => onPageChanged(totalPages)}
              disabled={currentPage >= totalPages}
              aria-label='Última página'
              className='hidden sm:inline-flex'
            >
              <ChevronsRight className='size-4' />
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}
