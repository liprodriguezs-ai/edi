import { Button } from '@/components/ui/button'
import {
  Table, TableBody, TableCell, TableHead, TableHeader, TableRow,
} from '@/components/ui/table'
import {
  buildTemplateCsv,
  csvToImportRows,
  type CollaboratorBatchImportResult,
  type CollaboratorImportValidation,
} from '@/modules/collaborator/helpers/collaboratorImport'
import { useCollaboratorImport } from '@/modules/collaborator/hooks/useCollaboratorImport'
import { PageHeader } from '@/shared/components/atoms/PageHeader'
import { toast } from '@/shared/hooks/useToast'
import { ArrowLeft, CheckCircle2, Download, FileUp, Upload, XCircle } from 'lucide-react'
import { useRef } from 'react'
import { Link } from 'react-router-dom'

function downloadTemplate() {
  const blob = new Blob([buildTemplateCsv()], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'plantilla-colaboradores.csv'
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

/** Paso 1: descargar plantilla y subir el CSV. */
function UploadStep({ onFile, pending }: { onFile: (_rows: Array<Record<string, string>>) => void; pending: boolean }) {
  const inputRef = useRef<HTMLInputElement>(null)

  const handleFile = async (file: File | undefined) => {
    if (!file) return
    const text = await file.text()
    const rows = csvToImportRows(text)
    if (rows.length === 0) {
      toast.error('El archivo está vacío o ninguna columna coincide con la plantilla')
      return
    }
    onFile(rows)
    if (inputRef.current) inputRef.current.value = ''
  }

  return (
    <div className="rounded-[14px] border border-dashed border-border bg-card px-6 py-12 text-center">
      <FileUp className="mx-auto size-10 text-[var(--ink-4)]" />
      <p className="mt-3 text-[14px] font-medium">Sube un archivo CSV con los colaboradores</p>
      <p className="mt-1 text-[12.5px] text-[var(--ink-3)]">Usa la plantilla para asegurar que las columnas coincidan.</p>
      <input
        ref={inputRef}
        type="file"
        accept=".csv,text/csv"
        className="hidden"
        onChange={(e) => handleFile(e.target.files?.[0])}
        disabled={pending}
      />
      <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
        <Button variant="outline" className="rounded-full" onClick={downloadTemplate}>
          <Download className="size-4" />
          Descargar plantilla
        </Button>
        <Button className="rounded-full" onClick={() => inputRef.current?.click()} disabled={pending}>
          <Upload className="size-4" />
          {pending ? 'Validando…' : 'Seleccionar archivo'}
        </Button>
      </div>
    </div>
  )
}

/** Paso 2: previsualización de la validación por fila. */
function PreviewStep({ validations, validCount, pending, onImport, onReset }: {
  validations: CollaboratorImportValidation[]
  validCount: number
  pending: boolean
  onImport: () => void
  onReset: () => void
}) {
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-[13.5px]">
          <strong>{validCount}</strong> de <strong>{validations.length}</strong> filas listas para importar
        </p>
        <div className="flex gap-2">
          <Button variant="outline" className="rounded-full" onClick={onReset} disabled={pending}>
            Cargar otro archivo
          </Button>
          <Button className="rounded-full" onClick={onImport} disabled={pending || validCount === 0}>
            <Upload className="size-4" />
            {pending ? 'Importando…' : `Importar ${validCount} válidos`}
          </Button>
        </div>
      </div>

      <div className="max-h-[60vh] overflow-y-auto rounded-[14px] border border-border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-12">#</TableHead>
              <TableHead>Nombre</TableHead>
              <TableHead>Estado</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {validations.map((validation) => (
              <TableRow key={validation.row_index}>
                <TableCell className="tabular-nums text-[var(--ink-4)]">{validation.row_index + 1}</TableCell>
                <TableCell className="font-medium">
                  {[validation.data.name, validation.data.last_name, validation.data.second_last_name]
                    .filter(Boolean).join(' ') || '—'}
                </TableCell>
                <TableCell>
                  {validation.has_errors ? (
                    <div className="flex items-start gap-1.5 text-[12.5px] text-destructive">
                      <XCircle className="mt-0.5 size-3.5 flex-none" />
                      <span>{validation.errors.join(' · ')}</span>
                    </div>
                  ) : (
                    <span className="flex items-center gap-1.5 text-[12.5px] text-[var(--green-deep)]">
                      <CheckCircle2 className="size-3.5" />
                      Válido
                    </span>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}

/** Paso 3: resultado del alta por lote. */
function ResultPanel({ result, onReset }: { result: CollaboratorBatchImportResult; onReset: () => void }) {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="rounded-[14px] border border-border bg-card p-5">
          <p className="text-[12px] text-[var(--ink-3)]">Importados</p>
          <p className="mt-1 text-[24px] font-semibold text-[var(--green-deep)]">{result.imported_count}</p>
        </div>
        <div className="rounded-[14px] border border-border bg-card p-5">
          <p className="text-[12px] text-[var(--ink-3)]">Con error</p>
          <p className="mt-1 text-[24px] font-semibold text-destructive">{result.failed_count}</p>
        </div>
      </div>

      {result.failed.length > 0 && (
        <div className="rounded-[14px] border border-border">
          <div className="border-b border-[var(--line-2)] px-4 py-3 text-sm font-semibold">Filas con error</div>
          <ul className="divide-y divide-[var(--line-2)]">
            {result.failed.map((failure) => (
              <li key={failure.row_index} className="flex items-start gap-2 px-4 py-2.5 text-[12.5px]">
                <span className="tabular-nums text-[var(--ink-4)]">#{failure.row_index + 1}</span>
                <span className="text-destructive">{failure.error}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <Button variant="outline" className="rounded-full" onClick={onReset}>
        <Upload className="size-4" />
        Importar otro archivo
      </Button>
    </div>
  )
}

/** Importación masiva de colaboradores desde CSV (§7.2). */
export const CollaboratorImportPage = () => {
  const { validations, result, validRows, validateMutation, batchMutation, reset } = useCollaboratorImport()

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Link to="/collaborator" className="grid size-9 place-items-center rounded-full border border-border hover:border-[var(--ink-3)]" aria-label="Volver">
          <ArrowLeft className="size-4" />
        </Link>
        <PageHeader title="Importar colaboradores" description="Carga masiva desde un archivo CSV" />
      </div>

      {result ? (
        <ResultPanel result={result} onReset={reset} />
      ) : validations ? (
        <PreviewStep
          validations={validations}
          validCount={validRows.length}
          pending={batchMutation.isPending}
          onImport={() => batchMutation.mutate(validRows)}
          onReset={reset}
        />
      ) : (
        <UploadStep onFile={(rows) => validateMutation.mutate(rows)} pending={validateMutation.isPending} />
      )}
    </div>
  )
}
