import type { CollaboratorBatchImportResult, CollaboratorImportValidation } from '@/modules/collaborator/helpers/collaboratorImport'
import type { CollaboratorFormValues, ICollaborator } from '@/modules/collaborator/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export const COLLABORATOR_FORM_INCLUDE = 'user,employee_position,pay_frequency,employee_shift'

type ImportRow = Record<string, string>

interface CollaboratorCustomMethods {
  me: () => Promise<ICollaborator>
  resendInvitation: (_id: string) => Promise<{ message: string }>
  terminate: (_id: string, _payload: { termination_date: string; termination_reason: string }) => Promise<ICollaborator>
  reactivate: (_id: string, _payload: { hire_date: string }) => Promise<ICollaborator>
  validateImport: (_rows: ImportRow[]) => Promise<CollaboratorImportValidation[]>
  batchImport: (_rows: ImportRow[]) => Promise<CollaboratorBatchImportResult>
}

export const CollaboratorResource = createCrudApi<
  ICollaborator,
  CollaboratorFormValues,
  Record<string, unknown>,
  CollaboratorCustomMethods
>({
  basePath: '/collaborator',
  customMethods: (basePath) => ({
    me: async () => {
      const { data } = await apiClient.get<{ data: ICollaborator }>(`${basePath}/me`)
      return data.data
    },
    resendInvitation: async (id: string): Promise<{ message: string }> => {
      const { data } = await apiClient.post<{ message: string }>(
        `${basePath}/${id}/resend_invitation`,
      )
      return data
    },
    terminate: async (id, payload) => {
      const { data } = await apiClient.post<{ data: ICollaborator }>(
        `${basePath}/${id}/terminate`,
        payload,
      )
      return data.data
    },
    reactivate: async (id, payload) => {
      const { data } = await apiClient.post<{ data: ICollaborator }>(
        `${basePath}/${id}/reactivate`,
        payload,
      )
      return data.data
    },
    validateImport: async (rows) => {
      const { data } = await apiClient.post<{ data: CollaboratorImportValidation[] }>(
        `${basePath}/validate_import`,
        { rows },
      )
      return data.data
    },
    batchImport: async (rows) => {
      const { data } = await apiClient.post<{ data: CollaboratorBatchImportResult }>(
        `${basePath}/batch_import`,
        { rows },
      )
      return data.data
    },
  }),
})
