import { ApiError } from '@/shared/api/apiClient'
import { ImportResource } from '@/modules/import/services/ImportResource'
import { ImportErrorDialog } from '@/modules/import/components/ImportErrorDialog'
import type { IValidateResponse } from '@/modules/import/schema'
import { IconFileUpload, IconLoader2, IconX } from '@tabler/icons-react'
import { useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { useMutation } from '@tanstack/react-query'

interface UploadStepProps {
  onValidated: (file: File, response: IValidateResponse) => void
}

/**
 * UploadStep — Step 1 of the import wizard.
 *
 * Uses a hidden <input type="file"> (no react-dropzone) triggered by a styled
 * button. Calls POST /import/validate via mutation and propagates the result
 * to the parent on success.
 */
export function UploadStep({ onValidated }: UploadStepProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: (file: File) => ImportResource.validateImport(file),
    onSuccess: (response) => {
      if (selectedFile) {
        onValidated(selectedFile, response)
      }
    },
    onError: (err) => {
      // Only HARD failures reach here (unreadable file / missing PAGOS sheet,
      // network, 5xx). Per-row validation results — including blocked errors —
      // come back as a normal response and are reviewed in Step 2, not here.
      const message =
        err instanceof ApiError
          ? err.payload?.message ?? err.message ?? 'No se pudo validar el archivo.'
          : 'Error al validar el archivo.'
      setErrorMessage(message)
    },
  })

  function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0] ?? null
    setSelectedFile(file)
    // Reset so the same file can be re-selected after clearing
    e.target.value = ''
  }

  function handleClearFile() {
    setSelectedFile(null)
  }

  function handleValidate() {
    if (selectedFile) {
      mutation.mutate(selectedFile)
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-base font-semibold">Paso 1 — Subir archivo</h3>
        <p className="text-sm text-muted-foreground">
          Selecciona el archivo .xlsx con los pagos a importar.
        </p>
      </div>

      {/* Hidden native file input */}
      <input
        ref={inputRef}
        type="file"
        accept=".xlsx"
        className="hidden"
        onChange={handleFileChange}
        aria-label="Seleccionar archivo Excel"
      />

      {/* File selection area */}
      {selectedFile ? (
        <div className="flex items-center gap-3 rounded-lg border border-dashed border-primary/40 bg-primary/5 px-4 py-3">
          <IconFileUpload className="h-5 w-5 shrink-0 text-primary" />
          <span className="flex-1 truncate text-sm font-medium">{selectedFile.name}</span>
          <button
            type="button"
            onClick={handleClearFile}
            className="rounded p-0.5 text-muted-foreground hover:text-foreground"
            aria-label="Quitar archivo"
          >
            <IconX className="h-4 w-4" />
          </button>
        </div>
      ) : (
        <button
          type="button"
          onClick={() => inputRef.current?.click()}
          className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed border-muted-foreground/30 px-4 py-8 text-sm text-muted-foreground transition-colors hover:border-primary/50 hover:bg-primary/5 hover:text-primary"
        >
          <IconFileUpload className="h-5 w-5" />
          Seleccionar archivo .xlsx
        </button>
      )}

      {/* Actions */}
      <div className="flex items-center gap-3">
        {selectedFile && (
          <Button
            variant="outline"
            size="sm"
            onClick={() => inputRef.current?.click()}
          >
            Cambiar archivo
          </Button>
        )}
        <Button
          onClick={handleValidate}
          disabled={!selectedFile || mutation.isPending}
          className="ml-auto"
        >
          {mutation.isPending ? (
            <>
              <IconLoader2 className="mr-2 h-4 w-4 animate-spin" />
              Validando…
            </>
          ) : (
            'Validar'
          )}
        </Button>
      </div>

      <ImportErrorDialog
        open={errorMessage !== null}
        message={errorMessage ?? ''}
        onClose={() => setErrorMessage(null)}
      />
    </div>
  )
}
