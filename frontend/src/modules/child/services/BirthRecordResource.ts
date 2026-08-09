import type { BirthRecordFormValues, IBirthRecord } from '@/modules/child/schemas/birthRecordSchema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const BirthRecordResource = createCrudApi<IBirthRecord, BirthRecordFormValues>({
  basePath: '/birth_record',
})
