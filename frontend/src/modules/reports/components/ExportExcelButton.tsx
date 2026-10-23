import { Button } from '@/components/ui/button'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { Download } from 'lucide-react'

interface ExportExcelButtonProps {
  permission: string
  isExporting: boolean
  onExport: () => unknown
}

export function ExportExcelButton({ permission, isExporting, onExport }: ExportExcelButtonProps) {
  return (
    <PermissionGuard permission={permission}>
      <Button
        variant='outline'
        onClick={() => onExport()}
        loading={isExporting}
        className='flex items-center gap-2'
      >
        {!isExporting && <Download className='h-4 w-4' />}
        Exportar Excel
      </Button>
    </PermissionGuard>
  )
}
