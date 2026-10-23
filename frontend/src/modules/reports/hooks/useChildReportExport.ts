import type { IChildReportQuery } from '@/modules/reports/schema'
import { toChildReportQuery } from '@/modules/reports/services/ChildReportResource'
import { toast } from '@/shared/hooks/useToast'
import type { ISearchParams } from '@/shared/interfaces/list.types'
import { useCallback, useState } from 'react'

interface ExportableResource {
  exportXlsx: (_query: IChildReportQuery) => Promise<Blob>
}

/**
 * "Exportar Excel" for the per-child reports: downloads the SAME filters
 * the list shows (search, centers, date range), all pages — no page/limit.
 */
export function useChildReportExport(
  resource: ExportableResource,
  paramsSearch: ISearchParams,
  fileName: string
) {
  const [isExporting, setIsExporting] = useState(false)

  const onExport = useCallback(async () => {
    setIsExporting(true)
    try {
      const { filters, search } = paramsSearch
      const blob = await resource.exportXlsx(toChildReportQuery({ filters, search }))

      if (blob.size <= 0) {
        toast.info('No hay datos para exportar')
        return
      }

      const url = window.URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `${fileName}.xlsx`
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
      window.URL.revokeObjectURL(url)
      toast.success('Reporte descargado correctamente')
    } catch {
      toast.error('Error al descargar el reporte, inténtalo más tarde')
    } finally {
      setIsExporting(false)
    }
  }, [resource, paramsSearch, fileName])

  return { onExport, isExporting }
}
