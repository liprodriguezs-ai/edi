import { Button } from '@/components/ui/button'
import { ReportPreviewDialog } from '@/modules/reports/components/ReportPreviewDialog'
import {
  PREVIEWABLE_REPORTS,
  REPORT_CATALOG,
  ReportsResource,
  saveBlob,
} from '@/modules/reports/services/ReportsResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { PermissionGuard } from '@/shared/components/PermissionGuard'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Eye, FileSpreadsheet, FileText, Megaphone } from 'lucide-react'
import { useState } from 'react'

const EXTENSION: Record<string, string> = { pdf: 'pdf', excel: 'xlsx', csv: 'csv' }

/**
 * Reportes (§15): catálogo de reportes generables (PDF/Excel) + generados
 * recientes con descarga.
 */
export const ReportsPage = () => {
  const queryClient = useQueryClient()
  const [generatingKey, setGeneratingKey] = useState<string | null>(null)
  const [preview, setPreview] = useState<{ key: string; label: string } | null>(null)
  const [publishTarget, setPublishTarget] = useState<{ key: string; label: string } | null>(null)

  const { data: generated = [] } = useQuery({
    queryKey: ['reports', 'generated'],
    queryFn: () => ReportsResource.listGenerated(),
  })

  const generateMutation = useMutation({
    mutationFn: async ({ key, format }: { key: string; format: 'pdf' | 'excel' }) => {
      const blob = await ReportsResource.generate(key, format)
      return { blob, key, format }
    },
    onSuccess: ({ blob, key, format }) => {
      saveBlob(blob, `${key}.${EXTENSION[format]}`)
      toast.success('Reporte generado')
      void queryClient.invalidateQueries({ queryKey: ['reports'] })
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo generar el reporte'
      )
    },
    onSettled: () => setGeneratingKey(null),
  })

  const downloadMutation = useMutation({
    mutationFn: async (report: {
      id: string
      file_name?: string | null
      report_key?: string
      format?: string
    }) => {
      const blob = await ReportsResource.download(report.id)
      saveBlob(
        blob,
        report.file_name ??
          `${report.report_key ?? 'reporte'}.${EXTENSION[report.format ?? 'pdf'] ?? 'pdf'}`
      )
    },
    onError: (error) => {
      toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo descargar')
    },
  })

  const publishMutation = useMutation({
    mutationFn: (key: string) => ReportsResource.publishToBoard(key),
    onSuccess: () => {
      toast.success('Reporte publicado en el tablero')
      setPublishTarget(null)
    },
    onError: (error) =>
      toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo publicar'),
  })

  const generate = (key: string, format: 'pdf' | 'excel') => {
    setGeneratingKey(`${key}:${format}`)
    generateMutation.mutate({ key, format })
  }

  return (
    <div className='space-y-6'>
      <PageHeader title='Reportes' description='Genera y descarga los reportes del condominio' />

      <div className='grid grid-cols-1 gap-3.5 md:grid-cols-2 lg:grid-cols-3'>
        {REPORT_CATALOG.map((report) => (
          <div
            key={report.key}
            className='flex flex-col rounded-[14px] border border-border bg-card p-4 shadow-sm'
          >
            <h3 className='text-[14.5px] font-semibold'>{report.label}</h3>
            <p className='mt-0.5 flex-1 text-[12.5px] text-[var(--ink-3)]'>{report.description}</p>
            {PREVIEWABLE_REPORTS.has(report.key) && (
              <Button
                variant='ghost'
                size='sm'
                className='mt-2 h-8 w-full justify-start rounded-full px-2.5 text-[12.5px] text-[var(--green-deep)]'
                onClick={() => setPreview({ key: report.key, label: report.label })}
              >
                <Eye className='size-3.5' />
                Vista previa
              </Button>
            )}
            <div className='mt-3 flex gap-2'>
              <Button
                variant='outline'
                size='sm'
                className='h-8 flex-1 rounded-full text-[12.5px]'
                onClick={() => generate(report.key, 'pdf')}
                disabled={generatingKey !== null}
              >
                <FileText className='size-3.5' />
                {generatingKey === `${report.key}:pdf` ? 'Generando…' : 'PDF'}
              </Button>
              <Button
                variant='outline'
                size='sm'
                className='h-8 flex-1 rounded-full text-[12.5px]'
                onClick={() => generate(report.key, 'excel')}
                disabled={generatingKey !== null}
              >
                <FileSpreadsheet className='size-3.5' />
                {generatingKey === `${report.key}:excel` ? 'Generando…' : 'Excel'}
              </Button>
            </div>
            <PermissionGuard permission='create.announcement'>
              <Button
                variant='ghost'
                size='sm'
                className='mt-2 h-8 w-full rounded-full text-[12.5px] text-[var(--ink-3)]'
                onClick={() => setPublishTarget({ key: report.key, label: report.label })}
                disabled={publishMutation.isPending}
              >
                <Megaphone className='size-3.5' />
                Publicar en tablero
              </Button>
            </PermissionGuard>
          </div>
        ))}
      </div>

      {generated.length > 0 && (
        <div className='overflow-hidden rounded-[14px] border border-border bg-card shadow-sm'>
          <div className='border-b border-[var(--line-2)] px-4 py-3.5'>
            <h3 className='text-sm font-semibold'>Generados recientes</h3>
          </div>
          {generated.map((report) => (
            <div
              key={report.id}
              className='flex items-center gap-3 border-b border-[var(--line-2)] px-4 py-3 text-[13px] last:border-b-0'
            >
              <FileText className='size-4 flex-none text-[var(--ink-3)]' aria-hidden />
              <span className='min-w-0 flex-1 truncate font-medium'>
                {report.file_name ?? report.report_key ?? 'Reporte'}
              </span>
              <span className='flex-none text-[12px] text-[var(--ink-4)]'>
                {report.created_at ? displayFormatDate(report.created_at) : ''}
              </span>
              <Button
                variant='ghost'
                size='sm'
                className='h-7 flex-none rounded-full px-2.5 text-[12px]'
                onClick={() => downloadMutation.mutate(report)}
                disabled={downloadMutation.isPending}
              >
                <Download className='size-3.5' />
                Descargar
              </Button>
            </div>
          ))}
        </div>
      )}

      <ReportPreviewDialog
        reportKey={preview?.key ?? null}
        label={preview?.label ?? ''}
        onClose={() => setPreview(null)}
      />

      <ConfirmDialog
        isOpen={publishTarget !== null}
        title='Publicar en tablero'
        description={`¿Publicar "${publishTarget?.label ?? ''}" en el tablero de avisos? Se generará el PDF y quedará visible para todos los residentes.`}
        confirmLabel='Publicar'
        isLoading={publishMutation.isPending}
        onConfirm={() => publishTarget && publishMutation.mutate(publishTarget.key)}
        onCancel={() => setPublishTarget(null)}
      />
    </div>
  )
}
