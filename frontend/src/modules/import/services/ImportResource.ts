import { ApiError, apiClient } from '@/shared/api/apiClient'
import { saveBlob } from '@/modules/reports/services/ReportsResource'
import type { IImportRun, IValidateResponse } from '@/modules/import/schema'

/**
 * Narrow an ApiError payload to a validate dry-run response (blocked rows) vs a
 * plain hard error (unreadable file, network, 5xx) that only carries a message.
 */
function isValidateResponse(payload: unknown): payload is IValidateResponse {
  return (
    typeof payload === 'object' &&
    payload !== null &&
    typeof (payload as IValidateResponse).blocked === 'number' &&
    Array.isArray((payload as IValidateResponse).rows)
  )
}

/**
 * ImportResource — HTTP client for the DataImport module endpoints.
 *
 * No createCrudApi wrapper needed since these are 4 fully custom endpoints
 * (no standard CRUD). Uses apiClient directly with the CollaboratorResource
 * FormData pattern.
 */
export const ImportResource = {
  /**
   * Download the .xlsx template for the current tenant.
   * Triggers a browser download automatically.
   */
  async downloadTemplate(): Promise<void> {
    const { data } = await apiClient.get<Blob>('/import/template', {
      responseType: 'blob',
    })
    saveBlob(data, 'template-importacion-pagos.xlsx')
  },

  /**
   * Run a synchronous dry-run validation on the uploaded file.
   * Returns preview counts and per-row issues.
   * Throws ApiError on HTTP 422 (blocked > 0) or other errors.
   */
  async validateImport(file: File): Promise<IValidateResponse> {
    const formData = new FormData()
    formData.append('file', file)
    try {
      const { data } = await apiClient.post<IValidateResponse>('/import/validate', formData)
      return data
    } catch (err) {
      // A 422 carrying a validation body (blocked rows) is a NORMAL result, not a
      // hard failure: return it so the wizard advances to Step 2 and shows the
      // per-row errors/warnings there. Genuine failures (unreadable file with only
      // a message, network, 5xx) keep throwing → surfaced as an error dialog.
      if (err instanceof ApiError && isValidateResponse(err.payload)) {
        return err.payload
      }
      throw err
    }
  },

  /**
   * Execute the import asynchronously.
   * Returns the import_run_id for polling.
   */
  async executeImport(file: File): Promise<{ import_run_id: string }> {
    const formData = new FormData()
    formData.append('file', file)
    const { data } = await apiClient.post<{ import_run_id: string }>('/import/execute', formData)
    return data
  },

  /**
   * Poll the status and progress of an ongoing import run.
   */
  async getStatus(id: string): Promise<IImportRun> {
    const { data } = await apiClient.get<IImportRun>(`/import/${id}/status`)
    return data
  },
}
