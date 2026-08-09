import {
  ConfigurationResource,
  LEGAL_TYPES,
  type LegalDocument,
} from '@/modules/configuration/services/ConfigurationResource'
import { useQuery } from '@tanstack/react-query'

export interface ClusterDocument {
  value: string
  label: string
  document: LegalDocument | null
}

/**
 * Documentos legales del condominio visibles al residente (§16.3.8): reglamento,
 * acta constitutiva y aviso de privacidad. Cada tipo trae el documento vigente
 * o null si el condominio aún no lo publicó.
 */
export function useClusterDocuments() {
  const { data = [], isLoading } = useQuery({
    queryKey: ['cluster-documents'],
    queryFn: async (): Promise<ClusterDocument[]> => {
      const documents = await Promise.all(
        LEGAL_TYPES.map((type) => ConfigurationResource.getCurrentLegalDocument(type.value)),
      )
      return LEGAL_TYPES.map((type, index) => ({
        value: type.value,
        label: type.label,
        document: documents[index],
      }))
    },
  })

  return { documents: data, isLoading }
}
