import type { ICompany } from '@/modules/company/schema'
import { CompanyResource } from '@/modules/company/services/CompanyResource'
import { useCompanyStore } from '@/modules/company/store/companyStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

export function useCompanyList() {
  return useBaseList<ICompany>({
    store: useCompanyStore,
    resource: CompanyResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'created_at', direction: 'desc' }],
    },
  })
}

