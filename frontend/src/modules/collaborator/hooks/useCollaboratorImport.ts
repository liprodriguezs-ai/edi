import type {
  CollaboratorBatchImportResult,
  CollaboratorImportValidation,
} from '@/modules/collaborator/helpers/collaboratorImport'
import { CollaboratorResource } from '@/modules/collaborator/services/CollaboratorResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'

type ImportRow = Record<string, string>

/**
 * Flujo de importación de colaboradores (§7.2): valida las filas sin persistir,
 * luego da de alta por lote solo las válidas. Mantiene las filas enviadas para
 * poder reenviar exactamente las que pasaron la validación.
 */
export function useCollaboratorImport() {
  const [submittedRows, setSubmittedRows] = useState<ImportRow[]>([])
  const [validations, setValidations] = useState<CollaboratorImportValidation[] | null>(null)
  const [result, setResult] = useState<CollaboratorBatchImportResult | null>(null)

  const validateMutation = useMutation({
    mutationFn: (rows: ImportRow[]) => CollaboratorResource.validateImport(rows),
    onSuccess: (data, rows) => {
      setSubmittedRows(rows)
      setValidations(data)
      setResult(null)
    },
    onError: (error) => toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo validar el archivo'),
  })

  const batchMutation = useMutation({
    mutationFn: (rows: ImportRow[]) => CollaboratorResource.batchImport(rows),
    onSuccess: (data) => {
      setResult(data)
      toast.success(`${data.imported_count} colaborador(es) importado(s)`)
    },
    onError: (error) => toast.error(error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo completar la importación'),
  })

  const validRows = (validations ?? [])
    .filter((validation) => !validation.has_errors)
    .map((validation) => submittedRows[validation.row_index])
    .filter(Boolean)

  const reset = () => {
    setSubmittedRows([])
    setValidations(null)
    setResult(null)
  }

  return { validations, result, validRows, validateMutation, batchMutation, reset }
}
