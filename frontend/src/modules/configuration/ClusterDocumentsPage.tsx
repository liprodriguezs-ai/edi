import { useClusterDocuments } from '@/modules/configuration/hooks/useClusterDocuments'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { ArrowLeft, ExternalLink, FileText } from 'lucide-react'
import { Link } from 'react-router-dom'

/**
 * Documentos legales del condominio para el residente (§16.3.8): reglamento, acta
 * constitutiva y aviso de privacidad, con enlace al documento vigente.
 */
export const ClusterDocumentsPage = () => {
  const { documents, isLoading } = useClusterDocuments()

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Link to="/my-unit" className="grid size-9 place-items-center rounded-full border border-border hover:border-[var(--ink-3)]" aria-label="Volver">
          <ArrowLeft className="size-4" />
        </Link>
        <PageHeader title="Documentos del condominio" description="Reglamento, acta constitutiva y aviso de privacidad" />
      </div>

      {isLoading ? (
        <p className="py-12 text-center text-sm text-[var(--ink-3)]">Cargando documentos…</p>
      ) : (
        <div className="grid grid-cols-1 gap-3.5 md:grid-cols-2 lg:grid-cols-3">
          {documents.map((entry) => (
            <div key={entry.value} className="flex flex-col rounded-[14px] border border-border bg-card p-5 shadow-sm">
              <div className="flex items-start gap-3">
                <span className="grid size-10 flex-none place-items-center rounded-full bg-[var(--green-bg)] text-[var(--green)]">
                  <FileText className="size-5" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="text-[14px] font-semibold">{entry.label}</p>
                  {entry.document ? (
                    <p className="text-[12px] text-[var(--ink-3)]">
                      Versión {entry.document.version ?? 1}
                      {entry.document.published_at ? ` · ${displayFormatDate(entry.document.published_at, 'mediumDate')}` : ''}
                    </p>
                  ) : (
                    <p className="text-[12px] text-[var(--ink-4)]">Aún no disponible</p>
                  )}
                </div>
              </div>

              {entry.document?.file_url ? (
                <a
                  href={entry.document.file_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="mt-4 inline-flex items-center justify-center gap-1.5 rounded-full border border-border px-4 py-2 text-[13px] font-medium hover:border-[var(--ink-3)]"
                >
                  <ExternalLink className="size-3.5" />
                  Ver documento
                </a>
              ) : (
                <span className="mt-4 inline-flex items-center justify-center rounded-full border border-dashed border-border px-4 py-2 text-[13px] text-[var(--ink-4)]">
                  El condominio no lo ha publicado
                </span>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
