import type { ICompany } from '@/modules/company/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useCompanyStore = createEntityStore<ICompany>('company');

