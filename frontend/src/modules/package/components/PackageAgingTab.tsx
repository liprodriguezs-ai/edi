import { Button } from '@/components/ui/button'
import type { IAgingReport } from '@/modules/package/schema'
import { AgingBucketCard } from '@/modules/package/components/AgingBucketCard'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { TableEmptyState } from '@/shared/components/molecules/TableEmptyState'
import { TableLoadingState } from '@/shared/components/molecules/TableLoadingState'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { Download } from 'lucide-react'

interface PackageAgingTabProps {
  /** Reporte de antigüedad; undefined mientras carga o si hay error. */
  aging: IAgingReport | undefined
  isLoading: boolean
  isError: boolean
  exportPending: boolean
  onExport: () => void
}

/**
 * Contenido de la pestaña "Reporte de antigüedad" en PackageListPage.
 * Extrae AgingBucketCard + encabezado + exportación para mantener PackageListPage < 500 líneas.
 */
export function PackageAgingTab({ aging, isLoading, isError, exportPending, onExport }: PackageAgingTabProps) {
  return (
    <section className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-[13px] text-muted-foreground">
          Paquetes en portería agrupados por tiempo de espera
        </p>
        <PermissionGuard permission="read.report_package">
          <Button
            variant="outline"
            size="sm"
            className="flex items-center gap-1.5"
            onClick={onExport}
            disabled={exportPending}
          >
            <Download className="size-4" />
            Exportar Excel
          </Button>
        </PermissionGuard>
      </div>

      {isLoading ? (
        <TableLoadingState />
      ) : isError || !aging ? (
        <TableEmptyState title="No se pudo cargar el reporte" />
      ) : (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <AgingBucketCard
              label="0 a 2 días"
              count={aging.buckets.d0_2.count}
              entries={aging.buckets.d0_2.packages}
            />
            <AgingBucketCard
              label="3 a 7 días"
              count={aging.buckets.d3_7.count}
              entries={aging.buckets.d3_7.packages}
            />
            <AgingBucketCard
              label="Más de 7 días"
              count={aging.buckets.d7_plus.count}
              entries={aging.buckets.d7_plus.packages}
              isOver7
            />
          </div>
          <p className="text-[11.5px] text-muted-foreground">
            Total en portería:{' '}
            <strong className="text-foreground">{aging.total_pending}</strong> paquete
            {aging.total_pending !== 1 ? 's' : ''} · Generado:{' '}
            {displayFormatDate(aging.generated_at)}
          </p>
        </>
      )}
    </section>
  )
}
