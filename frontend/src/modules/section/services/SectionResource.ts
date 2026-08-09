import type { ISection, SectionFormValues } from '@/modules/section/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const SectionResource = createCrudApi<ISection, SectionFormValues>({
  basePath: '/section',
})
